import argparse
import json
import os
import sys
from datetime import datetime, timezone

from sqlalchemy import text


def _parse_args():
    p = argparse.ArgumentParser()
    p.add_argument("symbol")
    p.add_argument("interval", choices=["1m", "5m", "15m", "30m", "1h", "4h"])
    p.add_argument("key", help="Xn 或 Yn，例如 X2、Y3")
    p.add_argument("--engine", type=str, choices=["v1", "v2"], default="v2")
    p.add_argument("--stop", type=float, default=0.02)
    p.add_argument("--confirm-bars", type=int, default=30)
    p.add_argument("--tp-band", type=str, choices=["upper", "middle", "lower"], default=None)
    p.add_argument("--out", type=str, default="")
    return p.parse_args()


def _dt(v) -> datetime:
    if isinstance(v, datetime):
        dt = v
    else:
        s = str(v).strip()
        s = s.replace(" ", "T")
        if s.endswith("Z"):
            s = s[:-1] + "+00:00"
        dt = datetime.fromisoformat(s)
    if dt.tzinfo is not None:
        dt = dt.astimezone(timezone.utc).replace(tzinfo=None)
    return dt

def _fmt_dt(dt: datetime | None) -> str:
    if dt is None:
        return "-"
    return dt.strftime("%Y-%m-%d %H:%M:%S")


def _find_point(points: list[dict], label: str) -> dict | None:
    for p in points:
        if isinstance(p, dict) and p.get("label") == label:
            return p
    return None


def _iter_klines(db, *, table: str, symbol: str, start: datetime, end: datetime) -> list[dict]:
    return (
        db.execute(
            text(
                f"""
                SELECT open_time, open, high, low, close, boll_up, boll_mb, boll_dn
                FROM "{table}"
                WHERE symbol = :symbol
                  AND open_time >= :t_start
                  AND open_time <= :t_end
                ORDER BY open_time ASC
                """
            ),
            {"symbol": symbol, "t_start": start, "t_end": end},
        )
        .mappings()
        .all()
    )


def _confirm_time(db, *, table: str, symbol: str, point_time: datetime, end_time: datetime, confirm_bars: int) -> datetime | None:
    kl = _iter_klines(db, table=table, symbol=symbol, start=point_time, end=end_time)
    if not kl:
        return None
    cnt = 0
    for k in kl:
        bu = k["boll_up"]
        bd = k["boll_dn"]
        if bu is None or bd is None:
            cnt = 0
            continue
        c = float(k["close"])
        if float(bd) <= c <= float(bu):
            cnt += 1
        else:
            cnt = 0
        if cnt >= confirm_bars:
            return k["open_time"]
    return None


def _simulate_trade(
    *,
    kind: str,
    anchor_label: str,
    structure_id: int,
    anchor_time: datetime,
    anchor_price: float,
    anchor_confirm_time: datetime,
    klines: list[dict],
    stop_pct: float,
    tp_band: str,
) -> dict | None:
    entry = None
    for i, k in enumerate(klines):
        if k["open_time"] < anchor_confirm_time:
            continue
        bu = k["boll_up"]
        bm = k["boll_mb"] if "boll_mb" in k else None
        bd = k["boll_dn"]
        if bu is None or bd is None:
            continue
        c = float(k["close"])
        if kind == "X":
            if c < float(bd):
                entry = {
                    "idx": i,
                    "time": k["open_time"],
                    "price": float(bd),
                }
                break
        else:
            if c > float(bu):
                entry = {
                    "idx": i,
                    "time": k["open_time"],
                    "price": float(bu),
                }
                break
    if entry is None:
        return None

    entry_price = float(entry["price"])
    if kind == "X":
        stop_price = entry_price * (1.0 - stop_pct)
    else:
        stop_price = entry_price * (1.0 + stop_pct)

    exit_reason = None
    exit_time = None
    exit_price = None
    tp_band_price = None

    for k in klines[entry["idx"] + 1 :]:
        bu = k["boll_up"]
        bm = k["boll_mb"] if "boll_mb" in k else None
        bd = k["boll_dn"]
        lo = float(k["low"])
        hi = float(k["high"])
        c = float(k["close"])

        if kind == "X":
            if lo <= stop_price:
                exit_reason = "SL"
                exit_time = k["open_time"]
                exit_price = stop_price
                break
            band_val = None
            if tp_band == "upper":
                band_val = float(bu) if bu is not None else None
            elif tp_band == "middle":
                band_val = float(bm) if bm is not None else None
            elif tp_band == "lower":
                band_val = float(bd) if bd is not None else None
            if band_val is not None and c > band_val:
                exit_reason = "TP"
                exit_time = k["open_time"]
                exit_price = band_val
                tp_band_price = band_val
                break
        else:
            if hi >= stop_price:
                exit_reason = "SL"
                exit_time = k["open_time"]
                exit_price = stop_price
                break
            band_val = None
            if tp_band == "upper":
                band_val = float(bu) if bu is not None else None
            elif tp_band == "middle":
                band_val = float(bm) if bm is not None else None
            elif tp_band == "lower":
                band_val = float(bd) if bd is not None else None
            if band_val is not None and c < band_val:
                exit_reason = "TP"
                exit_time = k["open_time"]
                exit_price = band_val
                tp_band_price = band_val
                break

    if exit_reason is None:
        last = klines[-1]
        exit_reason = "FORCE"
        exit_time = last["open_time"]
        exit_price = float(last["close"])

    if kind == "X":
        ret_pct = (float(exit_price) - entry_price) / entry_price
    else:
        ret_pct = (entry_price - float(exit_price)) / entry_price

    is_win = exit_reason == "TP" or (exit_reason == "FORCE" and ret_pct > 0)

    return {
        "structure_id": structure_id,
        "anchor_label": anchor_label,
        "anchor_time": anchor_time,
        "anchor_price": anchor_price,
        "anchor_confirm_time": anchor_confirm_time,
        "entry_time": entry["time"],
        "entry_price": entry_price,
        "stop_price": stop_price,
        "exit_reason": exit_reason,
        "exit_time": exit_time,
        "exit_price": float(exit_price),
        "tp_band_price": tp_band_price,
        "ret_pct": ret_pct,
        "win": is_win,
    }


def main():
    args = _parse_args()
    symbol = args.symbol.upper()
    interval = args.interval
    key = args.key.upper()
    if not (len(key) >= 2 and key[0] in ("X", "Y") and key[1:].isdigit() and int(key[1:]) > 1):
        print("key 无效，需形如 X2 或 Y3 且 n>1")
        sys.exit(1)
    n = int(key[1:])
    kind = key[0]
    anchor_label = f"{kind}{n-1}"
    tp_band = args.tp_band
    if tp_band is None:
        tp_band = "upper" if kind == "X" else "lower"

    root = os.path.dirname(os.path.abspath(__file__))
    backend_dir = os.path.join(root, "backend")
    if backend_dir not in sys.path:
        sys.path.insert(0, backend_dir)

    from app.database import SessionLocal
    from app.repositories.kline_repo import INTERVAL_TABLE

    table = INTERVAL_TABLE[interval]
    structures_table = "oscillation_structures_v2" if args.engine == "v2" else "oscillation_structures"

    db = SessionLocal()
    try:
        last_k = db.execute(
            text(
                f"""
                SELECT open_time
                FROM "{table}"
                WHERE symbol = :symbol
                ORDER BY open_time DESC
                LIMIT 1
                """
            ),
            {"symbol": symbol},
        ).mappings().first()
        if last_k is None:
            print("找不到K线数据")
            sys.exit(2)
        last_open_time = last_k["open_time"]

        rows = db.execute(
            text(
                f"""
                SELECT id, x_points, y_points, start_time, end_time
                FROM {structures_table}
                WHERE symbol = :symbol AND interval = :interval
                ORDER BY id ASC
                """
            ),
            {"symbol": symbol, "interval": interval},
        ).mappings().all()

        out_path = args.out.strip()
        if not out_path:
            out_path = os.path.join(root, f"backtest_{symbol}_{interval}_{key}_{args.engine}.txt")

        total_trades = 0
        wins = 0
        losses = 0
        skipped = 0
        total_ret = 0.0
        losses_no_target = 0
        losses_target_after_stop = 0
        losses_target_before_stop = 0

        with open(out_path, "w", encoding="utf-8") as f:
            f.write(
                f"engine={args.engine} symbol={symbol} interval={interval} key={key} stop={args.stop} confirm_bars={args.confirm_bars} tp_band={tp_band}\n"
            )

        loss_detail_path = os.path.join(root, f"backtest_{symbol}_{interval}_{key}_{args.engine}_losses.txt")
        with open(loss_detail_path, "w", encoding="utf-8") as f:
            f.write("结构ID,anchor_label,target_label,target_exists,target_time,exit_time,分类\n")

        for r in rows:
            x_points = r["x_points"]
            y_points = r["y_points"]
            x_list = x_points if isinstance(x_points, list) else json.loads(x_points or "[]")
            y_list = y_points if isinstance(y_points, list) else json.loads(y_points or "[]")
            if kind == "X":
                anchor = _find_point(x_list, anchor_label)
                target = _find_point(x_list, f"{kind}{n}")
            else:
                anchor = _find_point(y_list, anchor_label)
                target = _find_point(y_list, f"{kind}{n}")
            if not anchor:
                skipped += 1
                continue

            structure_id = int(r["id"])
            anchor_time = _dt(anchor["time"])
            anchor_price = float(anchor["price"])
            end_time = r["end_time"] if r["end_time"] is not None else last_open_time
            end_time = _dt(end_time)
            if end_time <= anchor_time:
                skipped += 1
                continue

            anchor_confirm_time = _confirm_time(
                db,
                table=table,
                symbol=symbol,
                point_time=anchor_time,
                end_time=end_time,
                confirm_bars=int(args.confirm_bars),
            )
            if anchor_confirm_time is None:
                skipped += 1
                continue

            klines = _iter_klines(db, table=table, symbol=symbol, start=_dt(anchor_confirm_time), end=end_time)
            if not klines:
                skipped += 1
                continue

            trade = _simulate_trade(
                kind=kind,
                anchor_label=anchor_label,
                structure_id=structure_id,
                anchor_time=anchor_time,
                anchor_price=anchor_price,
                anchor_confirm_time=anchor_confirm_time,
                klines=klines,
                stop_pct=float(args.stop),
                tp_band=tp_band,
            )
            if trade is None:
                skipped += 1
                continue

            total_trades += 1
            total_ret += float(trade["ret_pct"])
            if trade["win"]:
                wins += 1
            else:
                losses += 1
                tgt_exists = target is not None
                tgt_time_str = str(target["time"]) if tgt_exists else "-"
                cls = "无目标点"
                if tgt_exists:
                    tgt_time = _dt(target["time"])
                    if trade["exit_time"] is not None and tgt_time > _dt(trade["exit_time"]):
                        losses_target_after_stop += 1
                        cls = "目标点止损后才形成"
                    else:
                        losses_target_before_stop += 1
                        cls = "目标点止损前已形成"
                else:
                    losses_no_target += 1
                with open(loss_detail_path, "a", encoding="utf-8") as f:
                    f.write(
                        "{sid},{al},{tl},{te},{tt},{xt},{cls}\n".format(
                            sid=trade["structure_id"],
                            al=anchor_label,
                            tl=f"{kind}{n}",
                            te="Y" if tgt_exists else "N",
                            tt=tgt_time_str if tgt_exists else "-",
                            xt=_fmt_dt(trade["exit_time"]),
                            cls=cls,
                        )
                    )

            with open(out_path, "a", encoding="utf-8") as f:
                if trade["exit_reason"] == "TP":
                    band_name = {"upper": "上轨", "middle": "中轨", "lower": "下轨"}[tp_band]
                    exit_mark = f"止盈价({band_name}) {trade['exit_price']:.6f}"
                elif trade["exit_reason"] == "SL":
                    exit_mark = f"止损价 {trade['exit_price']:.6f}"
                else:
                    exit_mark = f"强平价(收盘) {trade['exit_price']:.6f}"
                f.write(
                    "结构ID {sid}  {lbl} {at} {ap:.6f}  确认 {ct}  入场 {et} 成交价格 {ep:.6f}  结果 {rsn} {xt} {mark}  收益 {ret:.4%}\n".format(
                        sid=trade["structure_id"],
                        lbl=trade["anchor_label"],
                        at=_fmt_dt(trade["anchor_time"]),
                        ap=trade["anchor_price"],
                        ct=_fmt_dt(trade["anchor_confirm_time"]),
                        et=_fmt_dt(trade["entry_time"]),
                        ep=trade["entry_price"],
                        rsn=trade["exit_reason"],
                        xt=_fmt_dt(trade["exit_time"]),
                        mark=exit_mark,
                        ret=trade["ret_pct"],
                    )
                )

        win_rate = (wins / total_trades) if total_trades > 0 else 0.0
        avg_ret = (total_ret / total_trades) if total_trades > 0 else 0.0

        with open(out_path, "a", encoding="utf-8") as f:
            f.write(
                "\n汇总 total_trades={t} wins={w} losses={l} skipped={s} win_rate={wr:.4%} avg_ret={ar:.4%} total_ret={tr:.4%}\n".format(
                    t=total_trades,
                    w=wins,
                    l=losses,
                    s=skipped,
                    wr=win_rate,
                    ar=avg_ret,
                    tr=total_ret,
                )
            )
            f.write(
                "losses细分: 无目标点={no}, 目标点止损后才形成={after}, 目标点止损前已形成={before}\n".format(
                    no=losses_no_target, after=losses_target_after_stop, before=losses_target_before_stop
                )
            )

        print(
            json.dumps(
                {
                    "symbol": symbol,
                    "interval": interval,
                    "key": key,
                    "engine": args.engine,
                    "structures_table": structures_table,
                    "total_trades": total_trades,
                    "wins": wins,
                    "losses": losses,
                    "skipped": skipped,
                    "win_rate": round(win_rate, 6),
                    "avg_ret": round(avg_ret, 8),
                    "total_ret": round(total_ret, 8),
                    "out": out_path,
                    "losses_no_target": losses_no_target,
                    "losses_target_after_stop": losses_target_after_stop,
                    "losses_target_before_stop": losses_target_before_stop,
                    "loss_detail": loss_detail_path,
                },
                ensure_ascii=False,
            )
        )
    finally:
        db.close()


if __name__ == "__main__":
    main()
