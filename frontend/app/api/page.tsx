"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

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

const API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? "http://localhost:8000/api";

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
  return `${v.slice(0, 4)}...${v.slice(-4)}`;
}

export default function ApiPage() {
  const [accounts, setAccounts] = useState<TradingAccount[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const selected = useMemo(
    () => accounts.find((a) => a.id === selectedId) ?? null,
    [accounts, selectedId],
  );

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>("");
  const [result, setResult] = useState<unknown>(null);

  const [accountName, setAccountName] = useState("");
  const [accountGroup, setAccountGroup] = useState<"SHORT" | "MID" | "LONG">("MID");
  const [apiKey, setApiKey] = useState("");
  const [apiSecret, setApiSecret] = useState("");
  const [isActive, setIsActive] = useState(true);
  const [validate, setValidate] = useState(true);

  const [symbol, setSymbol] = useState("BTCUSDT");

  const loadAccounts = useCallback(async () => {
    setBusy(true);
    setError("");
    try {
      const rows = await apiGet<TradingAccount[]>("/binance/accounts?include_inactive=true");
      setAccounts(rows);
      setSelectedId((prev) => (prev && rows.some((r) => r.id === prev) ? prev : rows[0]?.id ?? null));
    } catch (e) {
      setError(String(e));
      setAccounts([]);
      setSelectedId(null);
    } finally {
      setBusy(false);
    }
  }, []);

  useEffect(() => {
    void loadAccounts();
  }, [loadAccounts]);

  async function createAccount() {
    setBusy(true);
    setError("");
    try {
      await apiPost<TradingAccount>("/binance/accounts", {
        account_name: accountName,
        account_group: accountGroup,
        api_key: apiKey,
        api_secret: apiSecret,
        is_active: isActive,
        validate,
      });
      setAccountName("");
      setApiKey("");
      setApiSecret("");
      setResult(null);
      await loadAccounts();
    } catch (e) {
      setError(String(e));
    } finally {
      setBusy(false);
    }
  }

  async function removeAccount(id: number) {
    setBusy(true);
    setError("");
    try {
      await apiDelete(`/binance/accounts/${id}`);
      setResult(null);
      await loadAccounts();
    } catch (e) {
      setError(String(e));
    } finally {
      setBusy(false);
    }
  }

  async function runAction(path: string) {
    if (!selected) return;
    setBusy(true);
    setError("");
    try {
      const data = await apiGet<unknown>(path);
      setResult(data);
    } catch (e) {
      setError(String(e));
      setResult(null);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-5 text-white">
      <div className="rounded-xl border border-white/10 bg-white/5 p-5">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div className="space-y-1">
            <h1 className="text-xl font-semibold tracking-tight">API</h1>
            <p className="text-sm text-white">
              管理币安 USDT 合约子账号 API Key，并查询账户信息/持仓/交易记录。
            </p>
          </div>
          <div className="flex items-center gap-2">
            <button
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm hover:bg-white/10 disabled:opacity-50"
              onClick={loadAccounts}
              disabled={busy}
            >
              刷新
            </button>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-5">
        <div className="rounded-xl border border-white/10 bg-white/5 p-4 md:col-span-2">
          <div className="text-sm font-medium">添加子账号</div>
          <div className="mt-3 grid grid-cols-1 gap-3">
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

            <div className="flex flex-wrap items-center gap-3 text-sm">
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={isActive}
                  onChange={(e) => setIsActive(e.target.checked)}
                />
                启用
              </label>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={validate}
                  onChange={(e) => setValidate(e.target.checked)}
                />
                创建时校验
              </label>
            </div>

            <button
              className="rounded-lg border border-white/10 bg-white/10 px-3 py-2 text-sm hover:bg-white/15 disabled:opacity-50"
              onClick={createAccount}
              disabled={busy || !accountName || !apiKey || !apiSecret}
            >
              添加
            </button>
            {error ? <div className="text-sm text-white">{error}</div> : null}
          </div>
        </div>

        <div className="rounded-xl border border-white/10 bg-white/5 md:col-span-3">
          <div className="border-b border-white/10 p-4 text-sm font-medium">子账号列表</div>
          <div className="max-h-[360px] overflow-auto">
            {accounts.length === 0 ? (
              <div className="p-4 text-sm text-white">暂无账号</div>
            ) : (
              <table className="w-full text-left text-sm">
                <thead className="sticky top-0 bg-[#0b0e11]">
                  <tr className="border-b border-white/10 text-xs text-white">
                    <th className="px-3 py-2">ID</th>
                    <th className="px-3 py-2">名称</th>
                    <th className="px-3 py-2">分组</th>
                    <th className="px-3 py-2">Key</th>
                    <th className="px-3 py-2">启用</th>
                    <th className="px-3 py-2">操作</th>
                  </tr>
                </thead>
                <tbody>
                  {accounts.map((a) => {
                    const active = a.id === selectedId;
                    return (
                      <tr
                        key={a.id}
                        className={`border-b border-white/5 hover:bg-white/5 ${active ? "bg-white/5" : ""}`}
                        onClick={() => setSelectedId(a.id)}
                        role="button"
                      >
                        <td className="px-3 py-2 font-mono text-xs">{a.id}</td>
                        <td className="px-3 py-2">{a.account_name}</td>
                        <td className="px-3 py-2">{a.account_group}</td>
                        <td className="px-3 py-2 font-mono text-xs">{maskKey(a.api_key)}</td>
                        <td className="px-3 py-2">{a.is_active ? "Y" : "N"}</td>
                        <td className="px-3 py-2">
                          <button
                            className="rounded border border-white/10 bg-white/5 px-2 py-1 text-xs hover:bg-white/10"
                            onClick={(e) => {
                              e.stopPropagation();
                              void removeAccount(a.id);
                            }}
                            disabled={busy}
                          >
                            删除
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-5">
        <div className="rounded-xl border border-white/10 bg-white/5 p-4 md:col-span-2">
          <div className="text-sm font-medium">查询</div>
          <div className="mt-3 space-y-3">
            <div>
              <div className="text-xs text-white">交易对（Trades/Orders）</div>
              <input
                className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
                value={symbol}
                onChange={(e) => setSymbol(e.target.value)}
              />
            </div>
            <div className="flex flex-wrap gap-2">
              <button
                className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm hover:bg-white/10 disabled:opacity-50"
                onClick={() => runAction(`/binance/accounts/${selectedId}/account`)}
                disabled={!selectedId || busy}
              >
                基本信息
              </button>
              <button
                className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm hover:bg-white/10 disabled:opacity-50"
                onClick={() => runAction(`/binance/accounts/${selectedId}/positions`)}
                disabled={!selectedId || busy}
              >
                持仓
              </button>
              <button
                className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm hover:bg-white/10 disabled:opacity-50"
                onClick={() =>
                  runAction(`/binance/accounts/${selectedId}/trades?symbol=${encodeURIComponent(symbol)}`)
                }
                disabled={!selectedId || busy || !symbol}
              >
                交易记录
              </button>
              <button
                className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm hover:bg-white/10 disabled:opacity-50"
                onClick={() => runAction(`/binance/accounts/${selectedId}/income`)}
                disabled={!selectedId || busy}
              >
                资金流水
              </button>
              <button
                className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm hover:bg-white/10 disabled:opacity-50"
                onClick={() =>
                  runAction(`/binance/accounts/${selectedId}/orders?symbol=${encodeURIComponent(symbol)}`)
                }
                disabled={!selectedId || busy || !symbol}
              >
                全部订单
              </button>
            </div>
          </div>
        </div>

        <div className="rounded-xl border border-white/10 bg-white/5 md:col-span-3">
          <div className="border-b border-white/10 p-4 text-sm font-medium">
            返回数据 {selected ? `· ${selected.account_name}` : ""}
          </div>
          <div className="p-4">
            {!result ? (
              <div className="text-sm text-white">请选择账号并点击查询按钮</div>
            ) : (
              <pre className="max-h-[520px] overflow-auto rounded-lg bg-[#0b0e11] p-3 text-xs text-white">
                {JSON.stringify(result, null, 2)}
              </pre>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
