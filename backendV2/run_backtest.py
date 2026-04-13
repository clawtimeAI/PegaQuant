#!/usr/bin/env python3
"""
PegaQuant 回测引擎 v2
用法：
  python run_backtest.py --symbol BTCUSDT --start 2024-01-01 --end 2024-12-31
  python run_backtest.py --symbol BTCUSDT --start 2024-01-01 --end 2024-12-31 --no-a-point
"""
import argparse, sys, os
_root = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if _root not in sys.path:
    sys.path.insert(0, _root)

from backendV2.backtest.engine import BacktestEngine, BacktestConfig
from backendV2.report.report import generate_report

LABELS = {"short":"短期(1m/5m)", "mid":"中期(15m/30m)", "long":"长期(1h/4h)"}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--symbol",     default="BTCUSDT")
    ap.add_argument("--start",      default="2024-01-01 00:00:00")
    ap.add_argument("--end",        default="2024-12-31 23:59:59")
    ap.add_argument("--equity",     type=float, default=10_000.0)
    ap.add_argument("--sl-pct",     type=float, default=0.01, help="固定止损百分比，例如 0.01=1%")
    ap.add_argument("--no-mouth",   action="store_true", help="关闭mouth_state过滤")
    ap.add_argument("--no-a-point", action="store_true", help="关闭A点过滤")
    ap.add_argument("--output",     default="./reports")
    args = ap.parse_args()

    cfg = BacktestConfig(
        symbol=args.symbol,
        start=args.start,
        end=args.end,
        initial_equity=args.equity,
        require_mouth=not args.no_mouth,
        require_a_point=not args.no_a_point,
        stop_loss_pct=float(args.sl_pct),
    )

    print("=" * 60)
    print(f"  PegaQuant 回测 v2  {cfg.symbol}")
    print(f"  {cfg.start} → {cfg.end}")
    print(f"  初始资金: {cfg.initial_equity:,.0f} USDT/账户")
    print(f"  过滤: mouth={'ON' if cfg.require_mouth else 'OFF'}  A点={'ON' if cfg.require_a_point else 'OFF'}")
    print("=" * 60)

    engine = BacktestEngine(cfg)
    try:
        result = engine.run()
    except Exception as e:
        import traceback; traceback.print_exc()
        sys.exit(1)

    print("\n" + "=" * 60)
    print(f"  总交易: {len(result.trades)} 笔")
    print()
    for acc, st in result.summary.items():
        print(f"  ── {LABELS[acc]} ──")
        print(f"     笔数:    {st['total_trades']}")
        print(f"     胜率:    {st['win_rate']*100:.1f}%")
        print(f"     总收益:  {st['total_pnl_pct']*100:+.2f}%  ({st['total_pnl']:+.2f} USDT)")
        print(f"     最大回撤: {st['max_drawdown']*100:.2f}%")
        print(f"     Sharpe:  {st['sharpe']:.2f}")
        print(f"     盈亏比:  {st['profit_factor']:.2f}" if st['profit_factor'] != float('inf') else "     盈亏比:  ∞")
        print(f"     止盈/止损/爆仓: {st['tp_count']}/{st['sl_count']}/{st.get('liq_count',0)}")
        print(f"     最终权益: {st['final_equity']:,.2f} USDT")
        print()

    generate_report(result, args.output)
    print("=" * 60)


if __name__ == "__main__":
    main()
