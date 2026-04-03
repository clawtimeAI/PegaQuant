"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

import {
  accountJsonWithoutAssets,
  formatUsdtAmount,
  listNonZeroAssets,
  parseUsdtFromBinanceAccount,
} from "../_lib/binanceUsdt";

type TradingAccount = {
  id: number;
  account_name: string;
  account_group: "SHORT" | "MID" | "LONG";
  exchange_name: string;
  api_key: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
};

type TabId = "overview" | "positions" | "trades" | "income" | "orders";

const API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? "http://localhost:8000/api";
const TABS: { id: TabId; label: string }[] = [
  { id: "overview", label: "基本信息与资金" },
  { id: "positions", label: "持仓" },
  { id: "trades", label: "交易记录" },
  { id: "income", label: "资金流水" },
  { id: "orders", label: "全部订单" },
];

async function apiGet<T>(path: string): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, { cache: "no-store" });
  if (!res.ok) throw new Error(await res.text());
  return (await res.json()) as T;
}

async function apiPost<T>(path: string, body: unknown): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });
  if (!res.ok) throw new Error(await res.text());
  return (await res.json()) as T;
}

async function apiDelete(path: string): Promise<void> {
  const res = await fetch(`${API_BASE}${path}`, { method: "DELETE" });
  if (!res.ok) throw new Error(await res.text());
}

function maskKey(v: string) {
  if (!v) return "-";
  if (v.length <= 8) return v;
  return `${v.slice(0, 4)}…${v.slice(-4)}`;
}

function groupBadgeClass(g: TradingAccount["account_group"]) {
  if (g === "SHORT") return "bg-rose-500/15 text-rose-200 border-rose-500/30";
  if (g === "LONG") return "bg-emerald-500/15 text-emerald-200 border-emerald-500/30";
  return "bg-white/10 text-white border-white/15";
}

function isPlainRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === "object" && v !== null && !Array.isArray(v);
}

function DataBlock({ data }: { data: unknown }) {
  if (Array.isArray(data) && data.length > 0 && data.every(isPlainRecord)) {
    const keys = Array.from(
      data.reduce((set, row) => {
        Object.keys(row).forEach((k) => set.add(k));
        return set;
      }, new Set<string>()),
    );
    const show = keys.slice(0, 14);
    return (
      <div className="overflow-x-auto rounded-lg border border-white/10">
        <table className="w-full min-w-[640px] text-left text-xs">
          <thead className="bg-[#0b0e11]/95 text-white">
            <tr>
              {show.map((k) => (
                <th key={k} className="whitespace-nowrap border-b border-white/10 px-2 py-2 font-medium">
                  {k}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.map((row, i) => (
              <tr key={i} className="border-b border-white/5 hover:bg-white/[0.03]">
                {show.map((k) => (
                  <td key={k} className="max-w-[220px] truncate px-2 py-1.5 font-mono text-[11px] text-white">
                    {formatCell(row[k])}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }

  if (isPlainRecord(data)) {
    return (
      <dl className="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
        {Object.entries(data).map(([k, v]) => (
          <div key={k} className="flex flex-col gap-0.5 border-b border-white/5 pb-2 sm:flex-row sm:items-baseline sm:gap-3">
            <dt className="shrink-0 text-[11px] uppercase tracking-wide text-white">{k}</dt>
            <dd className="break-all font-mono text-xs text-white">{formatCell(v)}</dd>
          </div>
        ))}
      </dl>
    );
  }

  return (
    <pre className="max-h-[min(60vh,560px)] overflow-auto rounded-lg bg-[#0b0e11] p-3 text-xs text-white">
      {JSON.stringify(data, null, 2)}
    </pre>
  );
}

function formatCell(v: unknown): string {
  if (v === null || v === undefined) return "—";
  if (typeof v === "object") return JSON.stringify(v);
  return String(v);
}

function UsdtFundingSection({ raw }: { raw: unknown }) {
  const s = parseUsdtFromBinanceAccount(raw);
  const items: { label: string; value: string | null; emphasize?: boolean }[] = [
    { label: "可用余额（可开新仓）", value: s.availableBalance, emphasize: true },
    { label: "钱包余额合计", value: s.totalWalletBalance, emphasize: true },
    { label: "保证金余额合计", value: s.totalMarginBalance },
    { label: "未实现盈亏（账户）", value: s.totalUnrealizedProfit },
    { label: "USDT 钱包余额（资产行）", value: s.walletBalance },
    { label: "USDT 保证金（资产行）", value: s.marginBalance },
    { label: "USDT 未实现盈亏（资产行）", value: s.unrealizedProfit },
    { label: "最大可转出", value: s.maxWithdrawAmount },
  ];
  const shown = items.filter((it) => it.value != null && String(it.value).length > 0);
  return (
    <div className="rounded-xl border border-emerald-500/25 bg-emerald-500/[0.06] p-4">
      <div className="text-sm font-semibold text-emerald-100">USDT 资金</div>
      {shown.length === 0 ? (
        <div className="mt-3 text-sm text-white/55">未解析到数值型资金字段（请确认接口为 U 本位账户）</div>
      ) : (
        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          {shown.map((it) => {
            const pnl = it.label.includes("盈亏");
            const n = pnl ? parseFloat(String(it.value)) : NaN;
            const up = Number.isFinite(n) && n >= 0;
            return (
              <div
                key={it.label}
                className={`rounded-lg border border-white/10 bg-[#0b0e11]/60 px-3 py-2 ${it.emphasize ? "ring-1 ring-emerald-500/20" : ""}`}
              >
                <div className="text-[11px] text-white/55">{it.label}</div>
                <div className={`mt-0.5 font-mono text-white ${it.emphasize ? "text-base" : "text-sm"}`}>
                  {pnl ? (
                    <span className={up ? "text-emerald-300" : "text-rose-300"}>{formatUsdtAmount(it.value)}</span>
                  ) : (
                    formatUsdtAmount(it.value)
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

function OtherMarginAssetsTable({ raw }: { raw: unknown }) {
  const rows = listNonZeroAssets(raw);
  if (rows.length === 0) return null;
  return (
    <div className="rounded-xl border border-white/10 bg-[#0b0e11]/40 p-3">
      <div className="text-xs font-medium text-white/80">其它保证金资产（余额不为 0）</div>
      <div className="mt-2 overflow-x-auto">
        <table className="w-full min-w-[480px] text-left text-[11px]">
          <thead className="text-white/50">
            <tr>
              <th className="border-b border-white/10 py-2 pr-2">资产</th>
              <th className="border-b border-white/10 py-2 pr-2">钱包</th>
              <th className="border-b border-white/10 py-2 pr-2">可用</th>
              <th className="border-b border-white/10 py-2">保证金</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.asset} className="border-b border-white/5">
                <td className="py-1.5 font-medium text-white">{r.asset}</td>
                <td className="font-mono text-white/90">{formatUsdtAmount(r.walletBalance)}</td>
                <td className="font-mono text-white/90">{formatUsdtAmount(r.availableBalance)}</td>
                <td className="font-mono text-white/90">{formatUsdtAmount(r.marginBalance)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function AddSubaccountDialog(props: {
  open: boolean;
  busy: boolean;
  error: string;
  onClose: () => void;
  onSubmit: (payload: {
    accountName: string;
    accountGroup: "SHORT" | "MID" | "LONG";
    apiKey: string;
    apiSecret: string;
    isActive: boolean;
    validate: boolean;
  }) => void;
}) {
  const { open, busy, error, onClose, onSubmit } = props;
  const [accountName, setAccountName] = useState("");
  const [accountGroup, setAccountGroup] = useState<"SHORT" | "MID" | "LONG">("MID");
  const [apiKey, setApiKey] = useState("");
  const [apiSecret, setApiSecret] = useState("");
  const [isActive, setIsActive] = useState(true);
  const [validate, setValidate] = useState(true);

  const resetForm = useCallback(() => {
    setAccountName("");
    setApiKey("");
    setApiSecret("");
    setAccountGroup("MID");
    setIsActive(true);
    setValidate(true);
  }, []);

  const handleClose = useCallback(() => {
    resetForm();
    onClose();
  }, [onClose, resetForm]);

  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/55 p-4 backdrop-blur-[2px]"
      role="presentation"
      onMouseDown={(e) => e.target === e.currentTarget && handleClose()}
    >
      <div
        className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-white/10 bg-[#12161c] shadow-xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="add-subaccount-title"
      >
        <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
          <h2 id="add-subaccount-title" className="text-base font-semibold text-white">
            添加子账户
          </h2>
          <button
            type="button"
            className="rounded-lg p-1 text-white hover:bg-white/10"
            onClick={handleClose}
            disabled={busy}
            aria-label="关闭"
          >
            ✕
          </button>
        </div>
        <div className="space-y-3 px-5 py-4">
          <div>
            <div className="text-xs text-white">账户名称</div>
            <input
              className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
              value={accountName}
              onChange={(e) => setAccountName(e.target.value)}
              placeholder="例如：短期-01"
            />
          </div>
          <div>
            <div className="text-xs text-white">账户分组</div>
            <select
              className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
              value={accountGroup}
              onChange={(e) => setAccountGroup(e.target.value as "SHORT" | "MID" | "LONG")}
            >
              <option value="SHORT">SHORT</option>
              <option value="MID">MID</option>
              <option value="LONG">LONG</option>
            </select>
          </div>
          <div>
            <div className="text-xs text-white">API Key</div>
            <input
              className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
              value={apiKey}
              onChange={(e) => setApiKey(e.target.value)}
              placeholder="Binance API Key"
            />
          </div>
          <div>
            <div className="text-xs text-white">API Secret</div>
            <input
              className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
              value={apiSecret}
              onChange={(e) => setApiSecret(e.target.value)}
              placeholder="Binance API Secret"
              type="password"
            />
          </div>
          <div className="flex flex-wrap items-center gap-4 text-sm">
            <label className="flex items-center gap-2 text-white">
              <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
              启用
            </label>
            <label className="flex items-center gap-2 text-white">
              <input type="checkbox" checked={validate} onChange={(e) => setValidate(e.target.checked)} />
              创建时校验
            </label>
          </div>
          {error ? <div className="rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-100">{error}</div> : null}
        </div>
        <div className="flex justify-end gap-2 border-t border-white/10 px-5 py-4">
          <button
            type="button"
            className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm text-white hover:bg-white/10 disabled:opacity-50"
            onClick={onClose}
            disabled={busy}
          >
            取消
          </button>
          <button
            type="button"
            className="rounded-lg border border-amber-500/40 bg-amber-500/15 px-4 py-2 text-sm font-medium text-amber-100 hover:bg-amber-500/25 disabled:opacity-50"
            disabled={busy || !accountName || !apiKey || !apiSecret}
            onClick={() =>
              onSubmit({
                accountName,
                accountGroup,
                apiKey,
                apiSecret,
                isActive,
                validate,
              })
            }
          >
            {busy ? "提交中…" : "保存"}
          </button>
        </div>
      </div>
    </div>
  );
}

export default function ApiPage() {
  const [accounts, setAccounts] = useState<TradingAccount[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const selected = useMemo(
    () => accounts.find((a) => a.id === selectedId) ?? null,
    [accounts, selectedId],
  );

  const [listBusy, setListBusy] = useState(false);
  const [listError, setListError] = useState("");
  const [addOpen, setAddOpen] = useState(false);
  const [addBusy, setAddBusy] = useState(false);
  const [addError, setAddError] = useState("");

  const [activeTab, setActiveTab] = useState<TabId>("overview");
  const [symbol, setSymbol] = useState("BTCUSDT");
  const [panelBusy, setPanelBusy] = useState(false);
  const [panelData, setPanelData] = useState<unknown>(null);
  const [panelError, setPanelError] = useState("");

  const loadAccounts = useCallback(async () => {
    setListBusy(true);
    setListError("");
    try {
      const rows = await apiGet<TradingAccount[]>("/binance/accounts?include_inactive=true");
      setAccounts(rows);
      setSelectedId((prev) => (prev && rows.some((r) => r.id === prev) ? prev : rows[0]?.id ?? null));
    } catch (e) {
      setListError(String(e));
      setAccounts([]);
      setSelectedId(null);
    } finally {
      setListBusy(false);
    }
  }, []);

  useEffect(() => {
    void loadAccounts();
  }, [loadAccounts]);

  const pathForTab = useCallback(
    (tab: TabId, accountId: number, sym: string): string | null => {
      switch (tab) {
        case "overview":
          return `/binance/accounts/${accountId}/account`;
        case "positions":
          return `/binance/accounts/${accountId}/positions`;
        case "trades":
          if (!sym.trim()) return null;
          return `/binance/accounts/${accountId}/trades?symbol=${encodeURIComponent(sym.trim().toUpperCase())}`;
        case "income":
          return `/binance/accounts/${accountId}/income`;
        case "orders":
          if (!sym.trim()) return null;
          return `/binance/accounts/${accountId}/orders?symbol=${encodeURIComponent(sym.trim().toUpperCase())}`;
        default:
          return null;
      }
    },
    [],
  );

  const refreshPanel = useCallback(async () => {
    if (!selectedId) return;
    const p = pathForTab(activeTab, selectedId, symbol);
    if (p === null) {
      setPanelData(null);
      setPanelError("请先填写交易对（例如 BTCUSDT）");
      return;
    }
    setPanelBusy(true);
    setPanelError("");
    try {
      const data = await apiGet<unknown>(p);
      setPanelData(data);
    } catch (e) {
      setPanelError(String(e));
      setPanelData(null);
    } finally {
      setPanelBusy(false);
    }
  }, [selectedId, activeTab, symbol, pathForTab]);

  /** 仅在选择账户 / 切换 Tab / 交易对变化（仅成交与订单 Tab）时自动拉取，避免 overview 下改 symbol 误触发 */
  const panelFetchKey = useMemo(() => {
    if (activeTab === "trades" || activeTab === "orders") return `${activeTab}:${symbol.trim()}`;
    return activeTab;
  }, [activeTab, symbol]);

  useEffect(() => {
    if (!selectedId) {
      setPanelData(null);
      setPanelError("");
      return;
    }
    let cancelled = false;
    const p = pathForTab(activeTab, selectedId, symbol);
    if (p === null) {
      setPanelData(null);
      setPanelError("请先填写交易对（例如 BTCUSDT）");
      setPanelBusy(false);
      return;
    }
    setPanelBusy(true);
    setPanelError("");
    void apiGet<unknown>(p)
      .then((data) => {
        if (!cancelled) setPanelData(data);
      })
      .catch((e) => {
        if (!cancelled) {
          setPanelError(String(e));
          setPanelData(null);
        }
      })
      .finally(() => {
        if (!cancelled) setPanelBusy(false);
      });
    return () => {
      cancelled = true;
    };
  }, [selectedId, panelFetchKey, pathForTab, activeTab, symbol]);

  async function handleAddSubmit(payload: {
    accountName: string;
    accountGroup: "SHORT" | "MID" | "LONG";
    apiKey: string;
    apiSecret: string;
    isActive: boolean;
    validate: boolean;
  }) {
    setAddBusy(true);
    setAddError("");
    try {
      await apiPost<TradingAccount>("/binance/accounts", {
        account_name: payload.accountName,
        account_group: payload.accountGroup,
        api_key: payload.apiKey,
        api_secret: payload.apiSecret,
        is_active: payload.isActive,
        validate: payload.validate,
      });
      setAddOpen(false);
      await loadAccounts();
    } catch (e) {
      setAddError(String(e));
    } finally {
      setAddBusy(false);
    }
  }

  async function removeAccount(id: number) {
    if (!window.confirm("确定删除该子账户配置？此操作不可恢复。")) return;
    setListBusy(true);
    setPanelError("");
    try {
      await apiDelete(`/binance/accounts/${id}`);
      setPanelData(null);
      await loadAccounts();
    } catch (e) {
      setPanelError(String(e));
    } finally {
      setListBusy(false);
    }
  }

  const needsSymbol = activeTab === "trades" || activeTab === "orders";

  const panelDisplayData = useMemo(() => {
    if (panelData === null) return null;
    if (activeTab !== "positions") return panelData;
    if (!Array.isArray(panelData)) return panelData;
    return panelData.filter((row) => {
      if (!isPlainRecord(row)) return false;
      const amt = parseFloat(String(row["positionAmt"] ?? 0));
      return Math.abs(amt) > 0;
    });
  }, [activeTab, panelData]);

  return (
    <div className="space-y-4 text-white">
      <div className="rounded-xl border border-white/10 bg-white/5 p-4 sm:p-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="space-y-1">
            <h1 className="text-xl font-semibold tracking-tight">API 与子账户</h1>
            <p className="text-sm text-white">
              左侧选择子账户，右侧查看交易所侧资金与持仓、成交、流水与订单。添加账户使用弹窗，密钥仅在提交时传送。
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm hover:bg-white/10 disabled:opacity-50"
              onClick={() => void loadAccounts()}
              disabled={listBusy}
            >
              {listBusy ? "刷新中…" : "刷新列表"}
            </button>
            <button
              type="button"
              className="rounded-lg border border-amber-500/40 bg-amber-500/15 px-3 py-2 text-sm font-medium text-amber-100 hover:bg-amber-500/25 disabled:opacity-50"
              onClick={() => {
                setAddError("");
                setAddOpen(true);
              }}
              disabled={listBusy}
            >
              添加子账户
            </button>
          </div>
        </div>
      </div>

      {listError ? (
        <div className="rounded-xl border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">{listError}</div>
      ) : null}

      <div className="flex min-h-[min(72vh,760px)] flex-col gap-4 lg:flex-row lg:gap-0 lg:rounded-xl lg:border lg:border-white/10 lg:bg-white/[0.03] lg:overflow-hidden">
        {/* 左侧列表 */}
        <aside className="flex w-full shrink-0 flex-col border border-white/10 bg-white/[0.04] lg:w-72 lg:border-0 lg:border-r lg:border-white/10 lg:rounded-none">
          <div className="flex items-center justify-between border-b border-white/10 px-3 py-3">
            <span className="text-sm font-medium text-white">子账户</span>
            <span className="text-xs text-white">{listBusy ? "加载中…" : `${accounts.length} 个`}</span>
          </div>
          <div className="max-h-[40vh] overflow-y-auto lg:max-h-none lg:flex-1">
            {accounts.length === 0 ? (
              <div className="px-3 py-6 text-center text-sm text-white">暂无子账户，请点击「添加子账户」</div>
            ) : (
              <ul className="p-2">
                {accounts.map((a) => {
                  const on = a.id === selectedId;
                  return (
                    <li key={a.id}>
                      <button
                        type="button"
                        className={`mb-1 flex w-full flex-col items-start rounded-lg border px-3 py-2.5 text-left transition ${
                          on
                            ? "border-amber-500/50 bg-amber-500/10"
                            : "border-transparent bg-white/[0.02] hover:border-white/10 hover:bg-white/[0.06]"
                        }`}
                        onClick={() => setSelectedId(a.id)}
                      >
                        <div className="flex w-full items-center gap-2">
                          <span className="min-w-0 flex-1 truncate font-medium text-white">{a.account_name}</span>
                          <span
                            className={`shrink-0 rounded border px-1.5 py-0.5 text-[10px] font-semibold ${groupBadgeClass(a.account_group)}`}
                          >
                            {a.account_group}
                          </span>
                        </div>
                        <div className="mt-1 flex w-full items-center justify-between gap-2 text-[11px] text-white">
                          <span className="truncate font-mono">{maskKey(a.api_key)}</span>
                          <span className={a.is_active ? "text-emerald-300" : "text-white"}>
                            {a.is_active ? "启用" : "停用"}
                          </span>
                        </div>
                      </button>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
        </aside>

        {/* 右侧详情 */}
        <main className="min-w-0 flex-1 border border-white/10 bg-[#0b0e11]/40 lg:border-0">
          {!selected ? (
            <div className="flex h-64 items-center justify-center p-8 text-sm text-white">请先在左侧选择一个子账户</div>
          ) : (
            <div className="flex h-full min-h-[480px] flex-col">
              <div className="flex flex-col gap-3 border-b border-white/10 px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 space-y-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-lg font-semibold text-white">{selected.account_name}</h2>
                    <span
                      className={`rounded border px-2 py-0.5 text-[11px] font-semibold ${groupBadgeClass(selected.account_group)}`}
                    >
                      {selected.account_group}
                    </span>
                    {!selected.is_active ? (
                      <span className="rounded border border-white/20 bg-white/5 px-2 py-0.5 text-[11px] text-white">
                        已停用
                      </span>
                    ) : null}
                  </div>
                  <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-white">
                    <span>
                      ID <span className="font-mono text-white">{selected.id}</span>
                    </span>
                    <span>
                      交易所 <span className="text-white">{selected.exchange_name}</span>
                    </span>
                    <span>
                      API Key <span className="font-mono text-white">{maskKey(selected.api_key)}</span>
                    </span>
                  </div>
                  <div className="text-[11px] text-white">
                    创建于 {new Date(selected.created_at).toLocaleString("zh-CN")} · 更新{" "}
                    {new Date(selected.updated_at).toLocaleString("zh-CN")}
                  </div>
                </div>
                <div className="flex shrink-0 flex-wrap gap-2">
                  <button
                    type="button"
                    className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm hover:bg-white/10 disabled:opacity-50"
                    onClick={() => void refreshPanel()}
                    disabled={panelBusy || !selectedId}
                  >
                    {panelBusy ? "加载中…" : "刷新当前页"}
                  </button>
                  <button
                    type="button"
                    className="rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-100 hover:bg-rose-500/20 disabled:opacity-50"
                    onClick={() => void removeAccount(selected.id)}
                    disabled={listBusy}
                  >
                    删除配置
                  </button>
                </div>
              </div>

              <div className="flex-1 overflow-auto">
                <div className="sticky top-0 z-10 flex flex-wrap gap-1 border-b border-white/10 bg-[#0b0e11]/95 px-2 py-2 backdrop-blur">
                  {TABS.map((t) => (
                    <button
                      key={t.id}
                      type="button"
                      className={`rounded-lg px-3 py-1.5 text-sm transition ${
                        activeTab === t.id
                          ? "bg-amber-500/20 text-amber-100"
                          : "text-white hover:bg-white/10"
                      }`}
                      onClick={() => setActiveTab(t.id)}
                    >
                      {t.label}
                    </button>
                  ))}
                </div>

                <div className="p-4">
                  {needsSymbol ? (
                    <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end">
                      <div className="grow sm:max-w-xs">
                        <div className="text-xs text-white">交易对（U 本位合约）</div>
                        <input
                          className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm uppercase text-white"
                          value={symbol}
                          onChange={(e) => setSymbol(e.target.value.toUpperCase())}
                          placeholder="BTCUSDT"
                        />
                      </div>
                      <button
                        type="button"
                        className="rounded-lg border border-white/10 bg-white/10 px-4 py-2 text-sm hover:bg-white/15 disabled:opacity-50"
                        onClick={() => void refreshPanel()}
                        disabled={panelBusy || !symbol.trim()}
                      >
                        查询
                      </button>
                    </div>
                  ) : null}

                  {activeTab === "overview" ? (
                    <div className="mb-6 space-y-2">
                      <div className="text-xs font-medium uppercase tracking-wide text-white">本地保存的配置</div>
                      <div className="rounded-lg border border-white/10 bg-white/[0.02] p-3 text-sm">
                        <DataBlock
                          data={{
                            id: selected.id,
                            account_name: selected.account_name,
                            account_group: selected.account_group,
                            exchange_name: selected.exchange_name,
                            api_key_masked: maskKey(selected.api_key),
                            is_active: selected.is_active,
                            created_at: selected.created_at,
                            updated_at: selected.updated_at,
                          }}
                        />
                      </div>
                      <div className="pt-2 text-xs font-medium uppercase tracking-wide text-white">交易所账户（实时）</div>
                    </div>
                  ) : null}

                  {panelError ? (
                    <div className="mb-4 rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-100">
                      {panelError}
                    </div>
                  ) : null}

                  {panelBusy && !panelData ? (
                    <div className="py-16 text-center text-sm text-white">加载中…</div>
                  ) : panelData !== null && !panelError ? (
                    activeTab === "overview" ? (
                      <div className="space-y-4">
                        <UsdtFundingSection raw={panelData} />
                        <OtherMarginAssetsTable raw={panelData} />
                        <div>
                          <div className="mb-2 text-xs font-medium text-white/70">其它账户字段</div>
                          <p className="mb-2 text-[11px] text-white/45">
                            已隐藏 <span className="font-mono">assets</span>、
                        <span className="font-mono">positions</span> 等大数组；完整数据请用 API / 日志排查。
                          </p>
                          <DataBlock data={accountJsonWithoutAssets(panelData)} />
                        </div>
                      </div>
                    ) : activeTab === "positions" ? (
                      Array.isArray(panelDisplayData) && panelDisplayData.length === 0 ? (
                        <div className="py-10 text-center text-sm text-white/65">
                          暂无持仓（已过滤掉仓位数量为 0 的合约）
                        </div>
                      ) : (
                        <DataBlock data={panelDisplayData} />
                      )
                    ) : (
                      <DataBlock data={panelData} />
                    )
                  ) : !panelError ? (
                    <div className="text-sm text-white">暂无数据</div>
                  ) : null}
                </div>
              </div>
            </div>
          )}
        </main>
      </div>

      <AddSubaccountDialog
        open={addOpen}
        busy={addBusy}
        error={addError}
        onClose={() => !addBusy && setAddOpen(false)}
        onSubmit={(p) => void handleAddSubmit(p)}
      />
    </div>
  );
}
