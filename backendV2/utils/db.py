"""
数据库工具 - 对接 webman PostgreSQL
"""
import os
import json
import warnings
from typing import List, Optional
import pandas as pd
import psycopg2
from psycopg2.extras import RealDictCursor

DB_CONFIG = {
    "host":     os.getenv("DB_HOST", "127.0.0.1"),
    "port":     int(os.getenv("DB_PORT", "5432")),
    "dbname":   os.getenv("DB_NAME", "btc_quant"),
    "user":     os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASS", "qweqwe123"),
}

INTERVALS = ["1m", "5m", "15m", "30m", "1h", "4h"]

INTERVAL_SECONDS = {
    "1m": 60, "5m": 300, "15m": 900,
    "30m": 1800, "1h": 3600, "4h": 14400,
}


def get_conn():
    return psycopg2.connect(**DB_CONFIG)


def load_klines(symbol: str, interval: str, start: str, end: str) -> pd.DataFrame:
    table = f"kline_{interval}"
    sql = f"""
        SELECT open_time, open, high, low, close, volume,
               boll_up, boll_mb, boll_dn, bw, mouth_state
        FROM {table}
        WHERE symbol = %s AND open_time >= %s AND open_time <= %s
          AND boll_up IS NOT NULL
        ORDER BY open_time ASC
    """
    conn = get_conn()
    try:
        with warnings.catch_warnings():
            warnings.filterwarnings(
                "ignore",
                message=r"pandas only supports SQLAlchemy connectable.*",
                category=UserWarning,
            )
            df = pd.read_sql(sql, conn, params=(symbol.upper(), start, end))
    finally:
        conn.close()

    if df.empty:
        return df

    df["open_time"] = pd.to_datetime(df["open_time"])
    df.set_index("open_time", inplace=True)
    for col in ["open", "high", "low", "close", "boll_up", "boll_mb", "boll_dn", "bw"]:
        df[col] = pd.to_numeric(df[col], errors="coerce")
    df["mouth_state"] = df["mouth_state"].fillna(0).astype(int)
    return df


def load_oscillation_structures(symbol: str, interval: str, start: str, end: str) -> List[dict]:
    sql = """
        SELECT id, x_points, y_points, a_point, episode,
               engine_state, start_time, end_time, status
        FROM oscillation_structures_v3
        WHERE symbol = %s AND interval = %s
        ORDER BY id ASC
    """
    conn = get_conn()
    try:
        with conn.cursor(cursor_factory=RealDictCursor) as cur:
            cur.execute(sql, (symbol.upper(), interval))
            rows = cur.fetchall()
    finally:
        conn.close()

    results = []
    for row in rows:
        r = dict(row)
        for f in ["x_points", "y_points", "a_point", "episode", "engine_state"]:
            if isinstance(r[f], str):
                try:
                    r[f] = json.loads(r[f])
                except Exception:
                    r[f] = None
        results.append(r)
    return results
