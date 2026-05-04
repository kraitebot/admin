/**
 * Alpine factory for the lifecycle scenario grid.
 *
 * Wraps the pure LifecycleEngine with reactive bookkeeping, save
 * debouncing, and the action handlers the Blade template binds to.
 */

(function () {
    'use strict';

    window.lifecycleGrid = function (config) {
        return {
            state: config.state,
            urls: config.urls,

            computed: new Map(),

            busy: false,
            saveTimer: null,
            dirtyFrameIds: new Set(),

            showCompare: false,
            compareScenarioId: null,
            compareState: null,

            branchModalOpen: false,
            branchForm: { name: '', t_index: 0 },

            init() {
                this.recompute();
                this.$watch('state', () => this.recompute(), { deep: true });
            },

            // ---- Recompute ----

            recompute() {
                this.computed = window.LifecycleEngine.computeAll(this.state);
            },

            stateAt(tokenId, tIndex) {
                const perFrame = this.computed.get(tokenId);
                if (!perFrame) return this.emptyState();
                return perFrame.get(tIndex) || this.emptyState();
            },

            emptyState() {
                return {
                    price: null,
                    filled_levels: new Set(),
                    wap: null,
                    tp: null,
                    sl: null,
                    status: 'closed',
                    close_reason: null,
                    realised_pnl: 0,
                    unrealised_pnl: 0,
                    total_pnl: 0,
                    ladder: [],
                    position_qty: 0,
                };
            },

            // ---- Cell readers ----

            getPrice(tokenId, tIndex) {
                const s = this.stateAt(tokenId, tIndex);
                if (s.price === null || s.price === undefined) return '';
                return Number(s.price.toFixed(8));
            },

            isFilled(tokenId, tIndex, level) {
                return this.stateAt(tokenId, tIndex).filled_levels.has(level);
            },

            // ---- Cell editors ----

            setPrice(tokenId, frame, value) {
                const num = Number(value);
                if (!Number.isFinite(num)) return;
                this.upsertEvent(tokenId, frame, 'set_price', { price: num });
            },

            toggleLimit(tokenId, frame, level) {
                if (level === 0) return;
                const alreadyFilledHere = this.frameHasMarkLimit(tokenId, frame, level);
                if (alreadyFilledHere) {
                    this.removeEventsOfType(tokenId, frame, 'mark_limit_filled', level);
                } else {
                    this.appendEvent(tokenId, frame, 'mark_limit_filled', { limit_index: level });
                }
                this.markDirty(frame);
            },

            openClose(tokenId, frame) {
                const s = this.stateAt(tokenId, frame.t_index);
                if (s.status !== 'open') return;
                const price = window.prompt('Close at price?', s.price);
                if (price === null) return;
                const qty = window.prompt('Quantity to close?', s.position_qty);
                if (qty === null) return;
                this.appendEvent(tokenId, frame, 'manual_close', {
                    price: Number(price),
                    qty: Number(qty),
                });
                this.markDirty(frame);
            },

            // ---- Event log mutators ----

            upsertEvent(tokenId, frame, eventType, data) {
                const existing = (frame.events || []).find(
                    e => e.scenario_token_id === tokenId && e.event_type === eventType
                );
                if (existing) {
                    existing.event_data = data;
                } else {
                    this.appendEvent(tokenId, frame, eventType, data);
                }
                this.markDirty(frame);
            },

            appendEvent(tokenId, frame, eventType, data) {
                if (!frame.events) frame.events = [];
                frame.events.push({
                    id: 'tmp-' + Math.random().toString(36).slice(2),
                    scenario_token_id: tokenId,
                    event_type: eventType,
                    event_data: data,
                });
            },

            removeEventsOfType(tokenId, frame, eventType, limitIndex = null) {
                if (!frame.events) return;
                frame.events = frame.events.filter(e => {
                    if (e.scenario_token_id !== tokenId) return true;
                    if (e.event_type !== eventType) return true;
                    if (limitIndex !== null && Number(e.event_data?.limit_index) !== limitIndex) return true;
                    return false;
                });
            },

            frameHasMarkLimit(tokenId, frame, level) {
                return (frame.events || []).some(e =>
                    e.scenario_token_id === tokenId
                    && e.event_type === 'mark_limit_filled'
                    && Number(e.event_data?.limit_index) === level
                );
            },

            // ---- Persistence ----

            markDirty(frame) {
                this.dirtyFrameIds.add(frame.id);
                if (this.saveTimer) clearTimeout(this.saveTimer);
                this.saveTimer = setTimeout(() => this.flush(), 500);
            },

            async flush() {
                if (this.dirtyFrameIds.size === 0) return;
                const ids = Array.from(this.dirtyFrameIds);
                this.dirtyFrameIds.clear();

                for (const id of ids) {
                    const frame = this.state.frames.find(f => f.id === id);
                    if (!frame) continue;
                    const url = this.urls.saveEvents.replace(/\/\d+\/events$/, `/${id}/events`);
                    const payload = {
                        events: (frame.events || []).map(e => ({
                            scenario_token_id: e.scenario_token_id,
                            event_type: e.event_type,
                            event_data: e.event_data,
                        })),
                    };
                    try {
                        await window.hubUiFetch(url, { method: 'PUT', body: JSON.stringify(payload) });
                    } catch (err) {
                        window.showToast('Save failed: ' + (err.message || 'unknown'), 'error');
                    }
                }
            },

            // ---- Frame ops ----

            async addFrame() {
                if (this.busy) return;
                this.busy = true;
                try {
                    const res = await window.hubUiFetch(this.urls.addFrame, {
                        method: 'POST',
                        body: JSON.stringify({}),
                    });
                    if (res.ok && res.data?.frame) {
                        this.state.frames.push(res.data.frame);
                    } else {
                        window.showToast(res.data?.error || 'Could not add frame', 'error');
                    }
                } catch (e) {
                    window.showToast(e.message || 'Network error', 'error');
                } finally {
                    this.busy = false;
                }
            },

            async deleteFrame(frame) {
                if (frame.t_index === 0) {
                    window.showToast('T0 cannot be deleted.', 'warning');
                    return;
                }
                if (!window.confirm('Delete ' + frame.label + '? Events on this frame will be removed.')) return;
                this.busy = true;
                try {
                    const url = this.urls.deleteFrame.replace(/\/\d+$/, '/' + frame.id);
                    const res = await window.hubUiFetch(url, { method: 'DELETE' });
                    if (res.ok) {
                        const idx = this.state.frames.findIndex(f => f.id === frame.id);
                        if (idx > -1) this.state.frames.splice(idx, 1);
                        // Re-densify locally too.
                        this.state.frames
                            .filter(f => f.t_index > frame.t_index)
                            .forEach(f => {
                                f.t_index = f.t_index - 1;
                                if (/^T\d+$/.test(f.label || '')) f.label = 'T' + f.t_index;
                            });
                    }
                } catch (e) {
                    window.showToast(e.message || 'Network error', 'error');
                } finally {
                    this.busy = false;
                }
            },

            async submitBranch() {
                if (!this.branchForm.name) return;
                this.busy = true;
                try {
                    const res = await window.hubUiFetch(this.urls.branch, {
                        method: 'POST',
                        body: JSON.stringify({
                            name: this.branchForm.name,
                            t_index: this.branchForm.t_index,
                        }),
                    });
                    if (res.ok && res.data?.redirect) {
                        window.location.href = res.data.redirect;
                        return;
                    }
                } catch (e) {
                    window.showToast(e.message || 'Network error', 'error');
                } finally {
                    this.busy = false;
                    this.branchModalOpen = false;
                }
            },

            // ---- Compare pane (skeleton — render to be expanded next pass) ----

            async loadCompare() {
                if (!this.compareScenarioId) {
                    this.compareState = null;
                    return;
                }
                const url = this.urls.data.replace('/0/data', `/${this.compareScenarioId}/data`);
                try {
                    const res = await window.hubUiFetch(url, { method: 'GET' });
                    if (res.ok) this.compareState = res.data;
                } catch (e) {
                    window.showToast(e.message || 'Network error', 'error');
                }
            },

            // ---- Portfolio aggregations ----

            portfolioPnl(tIndex) {
                let total = 0;
                for (const token of this.state.tokens) {
                    total += this.stateAt(token.id, tIndex).total_pnl;
                }
                return total;
            },

            portfolioStartingMargin() {
                let total = 0;
                for (const token of this.state.tokens) {
                    total += token.frozen_config.margin_per_position_usdt || 0;
                }
                return total;
            },

            portfolioPercent(tIndex) {
                const base = this.portfolioStartingMargin();
                if (base <= 0) return '';
                const pct = (this.portfolioPnl(tIndex) / base) * 100;
                return (pct >= 0 ? '+' : '') + pct.toFixed(2) + '%';
            },

            // ---- Display helpers ----

            formatPrice(price, token) {
                if (price === null || price === undefined) return '—';
                const precision = token?.frozen_config?.price_precision ?? 4;
                return Number(price).toFixed(precision);
            },

            formatUsdt(value) {
                if (value === null || value === undefined || !Number.isFinite(value)) return '—';
                const sign = value > 0 ? '+' : '';
                return sign + value.toFixed(2);
            },

            cellBackground(tokenId, tIndex) {
                const s = this.stateAt(tokenId, tIndex);
                if (s.status === 'closed' && s.close_reason === 'sl') return 'ui-bg-elevated';
                if (s.status === 'closed' && s.close_reason === 'tp') return 'ui-bg-elevated';
                return '';
            },

            pnlColor(tokenId, tIndex) {
                const v = this.stateAt(tokenId, tIndex).total_pnl;
                if (v > 0) return 'color: rgb(var(--ui-success))';
                if (v < 0) return 'color: rgb(var(--ui-danger))';
                return 'color: rgb(var(--ui-text-muted))';
            },

            portfolioColor(tIndex) {
                const v = this.portfolioPnl(tIndex);
                if (v > 0) return 'color: rgb(var(--ui-success))';
                if (v < 0) return 'color: rgb(var(--ui-danger))';
                return 'color: rgb(var(--ui-text))';
            },

            statusColor(tokenId, tIndex) {
                const s = this.stateAt(tokenId, tIndex);
                if (s.status === 'open') return 'color: rgb(var(--ui-success))';
                if (s.close_reason === 'tp') return 'color: rgb(var(--ui-success))';
                if (s.close_reason === 'sl') return 'color: rgb(var(--ui-danger))';
                return 'color: rgb(var(--ui-text-muted))';
            },
        };
    };
})();
