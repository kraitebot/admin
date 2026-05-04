/**
 * Lifecycle calculation engine.
 *
 * Pure functions, no DOM, no network. Given a scenario state (tokens
 * with frozen config + frames with events) it derives the per-token
 * state at every frame: ladder geometry, fills, WAP, TP, SL, realised
 * and unrealised PnL, and open/closed status.
 *
 * The engine is the source of truth the Alpine grid renders against.
 * Recompute is cheap (linear in events), so we recompute end-to-end
 * on every edit rather than maintaining incremental state.
 */

(function () {
    'use strict';

    /**
     * Build the static ladder for a token: every level's price and qty.
     * Compound geometry — each next level is prior × (1 ± gap).
     *
     * Index 0 = market entry. Indices 1..N = limit orders.
     */
    function buildLadder(token) {
        const cfg = token.frozen_config;
        const isLong = cfg.side === 'LONG';
        const gap = (cfg.percentage_gap || 0) / 100;
        const totalLimits = cfg.total_limit_orders || 0;
        const multipliers = (cfg.limit_quantity_multipliers || []).slice();
        const baseQty = cfg.base_quantity || 0;

        const levels = [];
        for (let i = 0; i <= totalLimits; i++) {
            const factor = isLong ? Math.pow(1 - gap, i) : Math.pow(1 + gap, i);
            const price = token.entry_price * factor;
            const mult = i < multipliers.length ? multipliers[i] : (multipliers[multipliers.length - 1] || 1);
            const qty = baseQty * mult;
            levels.push({
                index: i,
                price: price,
                qty: qty,
            });
        }
        return levels;
    }

    /**
     * SL price — fixed at the deepest possible limit ± SL%.
     * For LONG: SL is BELOW the deepest limit by SL%.
     * For SHORT: SL is ABOVE the deepest limit by SL%.
     */
    function computeSlPrice(token, ladder) {
        const cfg = token.frozen_config;
        const isLong = cfg.side === 'LONG';
        const slPercent = (cfg.stop_market_percentage || 0) / 100;
        const deepest = ladder[ladder.length - 1];
        return isLong
            ? deepest.price * (1 - slPercent)
            : deepest.price * (1 + slPercent);
    }

    /**
     * TP price for the current ladder depth.
     * Levels 0..N-1: WAP × (1 ± TP%)
     * Level N (deepest): WAP (breakeven)
     */
    function computeTpPrice(token, wap, deepestFilled, ladder) {
        if (wap === null || wap === undefined) return null;
        const cfg = token.frozen_config;
        const isLong = cfg.side === 'LONG';
        const tpPercent = (cfg.profit_percentage || 0) / 100;
        const totalLimits = ladder.length - 1;

        if (deepestFilled >= totalLimits) {
            return wap;
        }
        return isLong ? wap * (1 + tpPercent) : wap * (1 - tpPercent);
    }

    /**
     * Compute WAP from a Set of filled level indices.
     */
    function computeWap(filledLevels, ladder) {
        let totalQty = 0;
        let totalCost = 0;
        for (const idx of filledLevels) {
            const lvl = ladder[idx];
            if (!lvl) continue;
            totalQty += lvl.qty;
            totalCost += lvl.qty * lvl.price;
        }
        return totalQty > 0 ? totalCost / totalQty : null;
    }

    /**
     * Realised PnL from a closing trade given side, qty, entry WAP and
     * exit price. Long: (exit - wap) × qty. Short: (wap - exit) × qty.
     */
    function realisedPnl(side, qty, wap, exitPrice) {
        return side === 'LONG'
            ? (exitPrice - wap) * qty
            : (wap - exitPrice) * qty;
    }

    /**
     * Unrealised PnL given current price and the position's WAP.
     */
    function unrealisedPnl(side, qty, wap, currentPrice) {
        if (qty <= 0 || wap === null || currentPrice === null) return 0;
        return side === 'LONG'
            ? (currentPrice - wap) * qty
            : (wap - currentPrice) * qty;
    }

    /**
     * Initial per-token state at T0: market order filled, all limits
     * placed but unfilled, TP and SL set, position open.
     */
    function initialState(token) {
        const ladder = buildLadder(token);
        const filled = new Set([0]);
        const wap = computeWap(filled, ladder);
        const tp = computeTpPrice(token, wap, 0, ladder);
        const sl = computeSlPrice(token, ladder);

        return {
            price: token.entry_price,
            filled_levels: filled,
            wap: wap,
            tp: tp,
            sl: sl,
            status: 'open',
            close_reason: null,
            realised_pnl: 0,
            unrealised_pnl: 0,
            total_pnl: 0,
            ladder: ladder,
            position_qty: ladder[0].qty,
        };
    }

    /**
     * Apply one event to a token-state. Mutates and returns it.
     */
    function applyEvent(state, event, token) {
        const data = event.event_data || {};

        switch (event.event_type) {
            case 'set_price': {
                const newPrice = Number(data.price);
                if (!Number.isFinite(newPrice)) break;
                state.price = newPrice;
                break;
            }

            case 'mark_limit_filled': {
                if (state.status !== 'open') break;
                const idx = Number(data.limit_index);
                if (!Number.isInteger(idx) || idx < 1 || idx >= state.ladder.length) break;
                if (state.filled_levels.has(idx)) break;
                state.filled_levels.add(idx);
                state.wap = computeWap(state.filled_levels, state.ladder);
                state.position_qty = sumQty(state.filled_levels, state.ladder);
                state.tp = computeTpPrice(token, state.wap, deepestFilled(state.filled_levels), state.ladder);
                break;
            }

            case 'manual_close': {
                if (state.status !== 'open') break;
                const closePrice = Number(data.price);
                let closeQty = Number(data.qty);
                if (!Number.isFinite(closePrice) || !Number.isFinite(closeQty) || closeQty <= 0) break;
                closeQty = Math.min(closeQty, state.position_qty);
                state.realised_pnl += realisedPnl(token.frozen_config.side, closeQty, state.wap, closePrice);
                state.position_qty -= closeQty;
                if (state.position_qty <= 1e-12) {
                    state.position_qty = 0;
                    state.status = 'closed';
                    state.close_reason = 'manual';
                }
                break;
            }

            case 'apply_slippage': {
                // Stored as metadata for the next implicit fill. The
                // engine doesn't currently auto-fill on price-cross,
                // so this is operator-visible context only for now.
                break;
            }
        }
        return state;
    }

    /**
     * After all events at a frame are applied, check for auto TP/SL
     * triggers based on the current price level.
     */
    function applyAutoExits(state, token) {
        if (state.status !== 'open') return;
        const isLong = token.frozen_config.side === 'LONG';
        const price = state.price;

        // SL fires first (more conservative interpretation: if both
        // would fire in the same tick, the loss leg wins).
        if (state.sl !== null) {
            const triggered = isLong ? price <= state.sl : price >= state.sl;
            if (triggered) {
                state.realised_pnl += realisedPnl(token.frozen_config.side, state.position_qty, state.wap, state.sl);
                state.position_qty = 0;
                state.status = 'closed';
                state.close_reason = 'sl';
                return;
            }
        }

        if (state.tp !== null) {
            const triggered = isLong ? price >= state.tp : price <= state.tp;
            if (triggered) {
                state.realised_pnl += realisedPnl(token.frozen_config.side, state.position_qty, state.wap, state.tp);
                state.position_qty = 0;
                state.status = 'closed';
                state.close_reason = 'tp';
            }
        }
    }

    /**
     * Compute the per-token state at every frame for the whole
     * scenario. Returns a Map<scenario_token_id, Map<t_index, state>>.
     */
    function computeAll(scenario) {
        const result = new Map();
        const tokensById = new Map();

        for (const token of scenario.tokens) {
            tokensById.set(token.id, token);
            const initial = initialState(token);
            const perFrame = new Map();
            perFrame.set(0, finaliseState(initial, token));
            result.set(token.id, perFrame);
        }

        const frames = scenario.frames.slice().sort((a, b) => a.t_index - b.t_index);

        for (let i = 0; i < frames.length; i++) {
            const frame = frames[i];
            for (const token of scenario.tokens) {
                const perFrame = result.get(token.id);
                let state;

                if (frame.t_index === 0) {
                    // T0 events apply ON TOP of the freshly-built initial state.
                    state = cloneState(initialState(token));
                } else {
                    const prevIndex = frames[i - 1].t_index;
                    state = cloneState(perFrame.get(prevIndex));
                    state.realised_pnl = perFrame.get(prevIndex).realised_pnl;
                }

                const tokenEvents = (frame.events || []).filter(e => e.scenario_token_id === token.id);
                for (const evt of tokenEvents) {
                    applyEvent(state, evt, token);
                }

                applyAutoExits(state, token);
                perFrame.set(frame.t_index, finaliseState(state, token));
            }
        }

        return result;
    }

    function finaliseState(state, token) {
        state.unrealised_pnl = state.status === 'open'
            ? unrealisedPnl(token.frozen_config.side, state.position_qty, state.wap, state.price)
            : 0;
        state.total_pnl = state.realised_pnl + state.unrealised_pnl;
        return state;
    }

    function cloneState(state) {
        return {
            price: state.price,
            filled_levels: new Set(state.filled_levels),
            wap: state.wap,
            tp: state.tp,
            sl: state.sl,
            status: state.status,
            close_reason: state.close_reason,
            realised_pnl: state.realised_pnl,
            unrealised_pnl: 0,
            total_pnl: 0,
            ladder: state.ladder,
            position_qty: state.position_qty,
        };
    }

    function sumQty(filledLevels, ladder) {
        let total = 0;
        for (const idx of filledLevels) {
            const lvl = ladder[idx];
            if (lvl) total += lvl.qty;
        }
        return total;
    }

    function deepestFilled(filledLevels) {
        let max = -1;
        for (const idx of filledLevels) {
            if (idx > max) max = idx;
        }
        return max;
    }

    window.LifecycleEngine = {
        buildLadder,
        computeAll,
        initialState,
    };
})();
