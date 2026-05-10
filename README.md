<p align="center">
  <img src="https://kraite.com/logo.png" alt="Kraite" width="200">
</p>

<h1 align="center">Kraite Admin</h1>

<p align="center">
  The administration panel for Kraite — monitor, configure, and control the trading system.
</p>

---

## About

Kraite Admin is the back-office interface for managing the Kraite trading infrastructure. It provides:

- **Exchange Symbol Management** — view, configure, and override trading parameters per symbol
- **Position Monitoring** — real-time position tracking, P&L, ladder state across all exchanges
- **Account Management** — multi-exchange account configuration and balance overview
- **Backtesting Review** — approve/reject backtesting results, manage symbol eligibility
- **System Diagnostics** — step dispatcher status, queue health, API error logs
- **Indicator Overrides** — per-symbol TP/SL, gap percentages, direction overrides

## Architecture

Shares the `kraite` database with `ingestion.kraite.com`. All schema changes live in `kraitebot/core` — this repo has no migrations. Built with `brunocfalcao/hub-ui` for the component library and theme system.

## Requirements

- PHP 8.4+
- Laravel 12
- Node.js (for frontend build)
- Shared MySQL access (via Zeus)

## Disclaimer

> **This software is provided for educational and informational purposes only.**
>
> Cryptocurrency trading involves substantial risk of financial loss. Algorithmic trading amplifies this risk through automated execution at speeds that prevent human intervention. Past performance does not guarantee future results.
>
> **By using, forking, or referencing this code, you acknowledge that:**
> - You may lose some or all of your invested capital
> - The authors accept no responsibility for financial losses
> - This software is not financial advice
> - You are solely responsible for your trading decisions
> - Bugs, network failures, exchange outages, or market conditions can cause unexpected losses
>
> **Do not trade with money you cannot afford to lose.**

## License

Proprietary. All rights reserved.
