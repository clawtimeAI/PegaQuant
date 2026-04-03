from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    database_url: str = "postgresql+psycopg2://postgres:qweqwe123@localhost:5432/btc_quant"
    cors_origins: str = "http://localhost:3000"
    cors_origin_regex: str = r"^https?://(localhost|127\.0\.0\.1)(:\d+)?$"

    osc_confirm_bars: int = 30
    osc_break_pct: float = 0.05
    osc_break_extreme_pct: float = 0.02

    app_encryption_key: str = ""

    binance_usdm_base_url: str = "https://fapi.binance.com"
    binance_recv_window: int = 5000

    enable_kline_stream_service: bool = False
    kline_upstream_ws_url: str = "ws://127.0.0.1:8383"
    kline_symbols: str = "BTCUSDT"
    kline_intervals: str = "1m,5m,15m,30m,1h,4h"
    kline_buffer_size: int = 1500
    kline_sender_queue_size: int = 2000
    kline_boll_period: int = 400


settings = Settings()
