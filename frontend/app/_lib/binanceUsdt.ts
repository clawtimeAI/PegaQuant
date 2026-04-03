/** 解析币安 U 本位 /fapi/v2/account 返回体中的 USDT 资金展示 */

export type UsdtBalanceSummary = {
  /** 可用于开新仓的余额（账户级或 USDT 资产行） */
  availableBalance: string | null;
  walletBalance: string | null;
  marginBalance: string | null;
  unrealizedProfit: string | null;
  maxWithdrawAmount: string | null;
  totalWalletBalance: string | null;
  totalMarginBalance: string | null;
  totalUnrealizedProfit: string | null;
};

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === "object" && v !== null && !Array.isArray(v);
}

export function formatUsdtAmount(s: string | null | undefined, maxFrac = 8): string {
  if (s == null || s === "") return "—";
  const n = parseFloat(String(s));
  if (!Number.isFinite(n)) return String(s);
  return n.toLocaleString("zh-CN", {
    minimumFractionDigits: 2,
    maximumFractionDigits: maxFrac,
  });
}

/** 从 /fapi/v2/account 原始 JSON 提取 USDT 相关字段（账户级 + assets 中 USDT 行） */
export function parseUsdtFromBinanceAccount(raw: unknown): UsdtBalanceSummary {
  if (!isRecord(raw)) {
    return {
      availableBalance: null,
      walletBalance: null,
      marginBalance: null,
      unrealizedProfit: null,
      maxWithdrawAmount: null,
      totalWalletBalance: null,
      totalMarginBalance: null,
      totalUnrealizedProfit: null,
    };
  }
  const top = raw;
  let usdtRow: Record<string, unknown> | null = null;
  const assets = top["assets"];
  if (Array.isArray(assets)) {
    const row = assets.find((a) => isRecord(a) && String(a["asset"] ?? "").toUpperCase() === "USDT");
    if (isRecord(row)) usdtRow = row;
  }

  const pick = (r: Record<string, unknown> | null, key: string): string | null => {
    if (!r) return null;
    const v = r[key];
    if (v == null || v === "") return null;
    return String(v);
  };

  return {
    availableBalance: pick(usdtRow, "availableBalance") ?? pick(top, "availableBalance"),
    walletBalance: pick(usdtRow, "walletBalance"),
    marginBalance: pick(usdtRow, "marginBalance"),
    unrealizedProfit: pick(usdtRow, "unrealizedProfit"),
    maxWithdrawAmount: pick(usdtRow, "maxWithdrawAmount") ?? pick(top, "maxWithdrawAmount"),
    totalWalletBalance: pick(top, "totalWalletBalance"),
    totalMarginBalance: pick(top, "totalMarginBalance"),
    totalUnrealizedProfit: pick(top, "totalUnrealizedProfit"),
  };
}

export type AssetRow = { asset: string; walletBalance: string; availableBalance: string; marginBalance: string };

/** assets 中非零余额行（除 USDT 外可做「其它资产」表；USDT 已由摘要展示） */
export function listNonZeroAssets(raw: unknown, excludeAsset = "USDT"): AssetRow[] {
  if (!isRecord(raw)) return [];
  const assets = raw["assets"];
  if (!Array.isArray(assets)) return [];
  const out: AssetRow[] = [];
  for (const a of assets) {
    if (!isRecord(a)) continue;
    const asset = String(a["asset"] ?? "");
    if (!asset || asset.toUpperCase() === excludeAsset.toUpperCase()) continue;
    const wb = parseFloat(String(a["walletBalance"] ?? 0));
    const ab = parseFloat(String(a["availableBalance"] ?? 0));
    const mb = parseFloat(String(a["marginBalance"] ?? 0));
    if (!Number.isFinite(wb) && !Number.isFinite(ab)) continue;
    if (Math.abs(wb) < 1e-12 && Math.abs(ab) < 1e-12 && Math.abs(mb) < 1e-12) continue;
    out.push({
      asset,
      walletBalance: String(a["walletBalance"] ?? "—"),
      availableBalance: String(a["availableBalance"] ?? "—"),
      marginBalance: String(a["marginBalance"] ?? "—"),
    });
  }
  return out;
}

/** 给「其它字段」展示用：去掉 assets 大数组，避免与卡片重复 */
export function accountJsonWithoutAssets(raw: unknown): unknown {
  if (!isRecord(raw)) return raw;
  const rest = { ...raw } as Record<string, unknown>;
  delete rest.assets;
  delete rest.positions;
  return rest;
}
