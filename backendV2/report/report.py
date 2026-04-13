"""报告生成 v2"""
from __future__ import annotations
import json, os
from typing import TYPE_CHECKING
import pandas as pd
import numpy as np
if TYPE_CHECKING:
    from backendV2.backtest.engine import BacktestResult

LABELS = {"short":"短期(1m/5m)", "mid":"中期(15m/30m)", "long":"长期(1h/4h)"}
COLORS = {"short":"#E85D24", "mid":"#EF9F27", "long":"#1D9E75"}


def generate_report(result: "BacktestResult", output_dir: str = "./reports") -> str:
    os.makedirs(output_dir, exist_ok=True)
    cfg = result.config

    # 权益曲线（每小时采样）
    eq_json = {}
    for acc, s in result.equity_curves.items():
        sh = s.resample("1h").last().dropna()
        eq_json[acc] = {"times": [str(t) for t in sh.index], "values": [round(v,2) for v in sh.values]}

    # 统计表
    summary_rows = ""
    for acc, st in result.summary.items():
        color = COLORS.get(acc,"#888")
        label = LABELS.get(acc, acc)
        pf = f"{st['profit_factor']:.2f}" if st['profit_factor'] != float('inf') else "∞"
        pnl_c = "#1D9E75" if st["total_pnl"] >= 0 else "#E24B4A"
        summary_rows += f"""<tr>
          <td><b style="color:{color}">{label}</b></td>
          <td>{st['total_trades']}</td>
          <td>{st['win_rate']*100:.1f}%</td>
          <td style="color:{pnl_c}">{st['total_pnl_pct']*100:+.2f}%</td>
          <td style="color:#E24B4A">{st['max_drawdown']*100:.2f}%</td>
          <td>{st['sharpe']:.2f}</td><td>{pf}</td>
          <td>{st['tp_count']}/{st['sl_count']}/{st.get('liq_count',0)}</td>
          <td>{st['final_equity']:,.2f}</td></tr>"""

    def _ft(v):
        if v is None:
            return ""
        if hasattr(v, "strftime"):
            return v.strftime("%Y-%m-%d %H:%M")
        s = str(v)
        return s[:16] if len(s) >= 16 else s

    # 交易明细（全部）
    trade_rows = ""
    for i, t in enumerate(reversed(result.trades), start=1):
        pc = "#1D9E75" if t.pnl >= 0 else "#E24B4A"
        dc = "#1D9E75" if t.direction == "LONG" else "#E24B4A"
        et = _ft(getattr(t, "entry_kline_time", getattr(t, "entry_time", "")))
        ct = _ft(getattr(t, "close_kline_time", getattr(t, "close_time", "")))
        eiv = getattr(t, "entry_kline_interval", getattr(t, "interval", ""))
        civ = getattr(t, "close_kline_interval", "1m")
        holding = float(getattr(t, "notional", 0.0))
        trade_rows += f"""<tr>
          <td>{i}</td>
          <td>{LABELS.get(t.account,t.account)}</td>
          <td style="color:{dc}">{t.direction}</td>
          <td>{t.interval}</td>
          <td>{eiv}</td><td>{et}</td>
          <td>{civ}</td><td>{ct}</td>
          <td>{holding:,.0f}</td>
          <td>{t.entry_price:,.2f}</td><td>{t.close_price:,.2f}</td>
          <td>{t.stop_loss:,.2f}</td><td>{t.take_profit:,.2f}</td>
          <td style="color:{pc}">{t.pnl:+.2f}</td>
          <td style="color:{pc}">{t.pnl_pct*100:+.1f}%</td>
          <td>{t.close_reason}</td></tr>"""

    eq_js = json.dumps(eq_json)
    total = len(result.trades)
    all_pnl = sum(t.pnl for t in result.trades)

    html = f"""<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">
<title>PegaQuant 回测报告 v2</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
*{{box-sizing:border-box;margin:0;padding:0}}
body{{font-family:-apple-system,sans-serif;background:#0f1117;color:#e0e0e0;padding:24px;font-size:13px}}
h1{{font-size:20px;font-weight:500;color:#fff;margin-bottom:4px}}
.sub{{color:#666;margin-bottom:20px;font-size:12px}}
.cards{{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:18px}}
.card{{background:#1a1d27;border-radius:8px;padding:12px 14px}}
.card .l{{font-size:11px;color:#555;margin-bottom:3px}}
.card .v{{font-size:18px;font-weight:500}}
.box{{background:#1a1d27;border-radius:8px;padding:14px;margin-bottom:14px}}
.bt{{font-size:11px;color:#555;margin-bottom:10px;letter-spacing:.5px;text-transform:uppercase}}
table{{width:100%;border-collapse:collapse}}
th{{text-align:left;color:#555;font-weight:400;padding:5px 7px;border-bottom:1px solid #2a2d3a}}
td{{padding:5px 7px;border-bottom:1px solid #1e2130}}
canvas{{max-height:260px}}
</style></head><body>
<h1>PegaQuant 回测报告 v2</h1>
<div class="sub">{cfg.symbol} | {cfg.start[:10]} → {cfg.end[:10]} | 初始 {cfg.initial_equity:,.0f} USDT/账户 | BB(400,2) 20x 50% | 盈亏比≥1.5 · mouth过滤 · A点确认</div>

<div class="cards">
  <div class="card"><div class="l">总交易笔数</div><div class="v">{total}</div></div>
  <div class="card"><div class="l">总盈亏(3账户合计)</div><div class="v" style="color:{'#1D9E75' if all_pnl>=0 else '#E24B4A'}">{all_pnl:+,.0f}</div></div>
  {''.join(f'<div class="card"><div class="l">{LABELS[a]}</div><div class="v" style="color:{COLORS[a]}">{result.summary.get(a, {}).get("final_equity",0):,.0f}</div></div>' for a in ["short","mid","long"])}
</div>

<div class="box"><div class="bt">权益曲线</div><canvas id="ec"></canvas></div>

<div class="box"><div class="bt">账户绩效</div>
<table><thead><tr>
  <th>账户</th><th>笔数</th><th>胜率</th><th>总收益</th>
  <th>最大回撤</th><th>Sharpe</th><th>盈亏比</th><th>止盈/止损/爆仓</th><th>最终权益</th>
</tr></thead><tbody>{summary_rows}</tbody></table></div>

<div class="box">
<div class="bt">全部交易（可搜索）</div>
<div style="margin-bottom:10px">
  <input id="q" placeholder="搜索：账户/周期/原因/时间…" style="width:100%;padding:8px 10px;border-radius:6px;border:1px solid #2a2d3a;background:#0f1117;color:#e0e0e0;outline:none">
</div>
<table id="trades"><thead><tr>
  <th>#</th><th>账户</th><th>方向</th><th>策略周期</th>
  <th>成交K线周期</th><th>成交K线时间</th>
  <th>止盈/止损K线周期</th><th>止盈/止损K线时间</th>
  <th>持仓金额</th>
  <th>入场价</th><th>平仓价</th><th>止损</th><th>止盈</th>
  <th>盈亏</th><th>收益%</th><th>原因</th>
</tr></thead><tbody>{trade_rows}</tbody></table></div>

<script>
const D={eq_js};
const C={{"short":"#E85D24","mid":"#EF9F27","long":"#1D9E75"}};
const L={{"short":"短期","mid":"中期","long":"长期"}};
const all=[...new Set(Object.values(D).flatMap(d=>d.times))].sort();
new Chart(document.getElementById('ec').getContext('2d'),{{
  type:'line',
  data:{{labels:all,datasets:Object.entries(D).map(([a,d])=>({{'label':L[a]||a,
    data:all.map(t=>{{const i=d.times.indexOf(t);return i>=0?d.values[i]:null}}),
    borderColor:C[a]||'#888',backgroundColor:'transparent',
    borderWidth:1.5,pointRadius:0,tension:.2,spanGaps:true}}))
  }},
  options:{{responsive:true,interaction:{{mode:'index',intersect:false}},
    plugins:{{legend:{{labels:{{color:'#aaa',boxWidth:10,font:{{size:11}}}}}},
      tooltip:{{backgroundColor:'#1a1d27',titleColor:'#aaa',bodyColor:'#eee'}}}},
    scales:{{
      x:{{ticks:{{color:'#444',maxTicksLimit:8,font:{{size:10}}}},grid:{{color:'#1e2130'}}}},
      y:{{ticks:{{color:'#444',font:{{size:10}}}},grid:{{color:'#1e2130'}}}}
    }}
  }}
}});

const q=document.getElementById('q');
const rows=[...document.querySelectorAll('#trades tbody tr')];
q.addEventListener('input',()=>{{
  const s=q.value.trim().toLowerCase();
  for(const r of rows){{
    const t=r.innerText.toLowerCase();
    r.style.display = s==='' || t.includes(s) ? '' : 'none';
  }}
}});
</script></body></html>"""

    fname = os.path.join(output_dir, f"report_{cfg.symbol}_{cfg.start[:10]}_{cfg.end[:10]}.html")
    with open(fname, "w", encoding="utf-8") as f:
        f.write(html)

    if result.trades:
        import pandas as pd
        pd.DataFrame([t.__dict__ for t in result.trades]).to_csv(
            fname.replace(".html","_trades.csv"), index=False, encoding="utf-8-sig"
        )
    print(f"[Report] {fname}")
    return fname
