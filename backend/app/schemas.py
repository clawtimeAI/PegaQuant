from datetime import datetime
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


Interval = Literal["1m", "5m", "15m", "30m", "1h", "4h"]


class OscillationPoint(BaseModel):
    time: datetime
    price: float
    kind: Literal["X", "Y"]


class OscillationStructureOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    symbol: str
    interval: str
    status: str
    x_points: list[dict[str, Any]] = Field(default_factory=list)
    y_points: list[dict[str, Any]] = Field(default_factory=list)
    close_reason: str | None = None
    close_condition: dict[str, Any] | None = None
    engine_state: dict[str, Any] | None = None
    start_time: datetime | None = None
    end_time: datetime | None = None
    created_at: datetime
    updated_at: datetime


class OscillationRunIn(BaseModel):
    symbol: str
    interval: Interval
    start_time: datetime | None = None
    end_time: datetime | None = None
    confirm_bars: int | None = None
    break_pct: float | None = None
    break_extreme_pct: float | None = None


class TradingAccountOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    account_name: str
    account_group: str
    exchange_name: str
    api_key: str
    is_active: bool
    created_at: datetime
    updated_at: datetime


class TradingAccountCreateIn(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    account_name: str
    account_group: Literal["SHORT", "MID", "LONG"]
    api_key: str
    api_secret: str
    is_active: bool = True
    validate_credentials: bool = Field(True, alias="validate")
