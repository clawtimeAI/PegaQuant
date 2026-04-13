/*
 Navicat Premium Dump SQL

 Source Server         : mydb
 Source Server Type    : PostgreSQL
 Source Server Version : 170009 (170009)
 Source Host           : localhost:5432
 Source Catalog        : btc_quant
 Source Schema         : public

 Target Server Type    : PostgreSQL
 Target Server Version : 170009 (170009)
 File Encoding         : 65001

 Date: 24/03/2026 19:13:43
*/


-- ----------------------------
-- Table structure for kline_1m
-- ----------------------------
DROP TABLE IF EXISTS "public"."kline_1m";
CREATE TABLE "public"."kline_1m" (
  "id" bigserial NOT NULL,
  "symbol" text COLLATE "pg_catalog"."default" NOT NULL,
  "open" numeric(20,10) NOT NULL,
  "high" numeric(20,10) NOT NULL,
  "low" numeric(20,10) NOT NULL,
  "close" numeric(20,10) NOT NULL,
  "volume" numeric(28,12) NOT NULL,
  "amount" numeric(28,12) NOT NULL,
  "num_trades" int8 NOT NULL,
  "buy_volume" numeric(28,12) NOT NULL,
  "buy_amount" numeric(28,12) NOT NULL,
  "open_time" timestamp(6) NOT NULL,
  "close_time" timestamp(6) NOT NULL,
  "boll_up" numeric(20,10),
  "boll_mb" numeric(20,10),
  "boll_dn" numeric(20,10),
  "bw" numeric(20,10),
  "mouth_state" int2 NOT NULL DEFAULT 0,
  "is_check" int2 NOT NULL DEFAULT 0,
  "is_key" int2 NOT NULL DEFAULT 0
)
;

-- ----------------------------
-- Indexes structure for table kline_1m
-- ----------------------------
CREATE INDEX "kline_1m_open_time_idx" ON "public"."kline_1m" USING btree (
  "open_time" "pg_catalog"."timestamp_ops" DESC NULLS FIRST
);
CREATE INDEX "kline_1m_symbol_check_time_idx" ON "public"."kline_1m" USING btree (
  "symbol" COLLATE "pg_catalog"."default" "pg_catalog"."text_ops" ASC NULLS LAST,
  "is_check" "pg_catalog"."int2_ops" ASC NULLS LAST,
  "open_time" "pg_catalog"."timestamp_ops" ASC NULLS LAST
);
CREATE INDEX "kline_1m_symbol_time_idx" ON "public"."kline_1m" USING btree (
  "symbol" COLLATE "pg_catalog"."default" "pg_catalog"."text_ops" ASC NULLS LAST,
  "open_time" "pg_catalog"."timestamp_ops" ASC NULLS LAST
);

-- ----------------------------
-- Uniques structure for table kline_1m
-- ----------------------------
ALTER TABLE "public"."kline_1m" ADD CONSTRAINT "kline_1m_symbol_open_time_key" UNIQUE ("symbol", "open_time");

-- ----------------------------
-- Primary Key structure for table kline_1m
-- ----------------------------
ALTER TABLE "public"."kline_1m" ADD CONSTRAINT "kline_1m_pkey" PRIMARY KEY ("id", "open_time", "symbol");


-- ----------------------------
-- Table structure for kline_5m
-- ----------------------------
DROP TABLE IF EXISTS "public"."kline_5m";
CREATE TABLE "public"."kline_5m" (
  "id" bigserial NOT NULL,
  "symbol" text COLLATE "pg_catalog"."default" NOT NULL,
  "open" numeric(20,10) NOT NULL,
  "high" numeric(20,10) NOT NULL,
  "low" numeric(20,10) NOT NULL,
  "close" numeric(20,10) NOT NULL,
  "volume" numeric(28,12) NOT NULL,
  "amount" numeric(28,12) NOT NULL,
  "num_trades" int8 NOT NULL,
  "buy_volume" numeric(28,12) NOT NULL,
  "buy_amount" numeric(28,12) NOT NULL,
  "open_time" timestamp(6) NOT NULL,
  "close_time" timestamp(6) NOT NULL,
  "boll_up" numeric(20,10),
  "boll_mb" numeric(20,10),
  "boll_dn" numeric(20,10),
  "bw" numeric(20,10),
  "mouth_state" int2 NOT NULL DEFAULT 0,
  "is_check" int2 NOT NULL DEFAULT 0,
  "is_key" int2 NOT NULL DEFAULT 0
)
;

-- ----------------------------
-- Indexes structure for table kline_5m
-- ----------------------------
CREATE INDEX "kline_5m_open_time_idx" ON "public"."kline_5m" USING btree (
  "open_time" "pg_catalog"."timestamp_ops" DESC NULLS FIRST
);
CREATE INDEX "kline_5m_symbol_check_time_idx" ON "public"."kline_5m" USING btree (
  "symbol" COLLATE "pg_catalog"."default" "pg_catalog"."text_ops" ASC NULLS LAST,
  "is_check" "pg_catalog"."int2_ops" ASC NULLS LAST,
  "open_time" "pg_catalog"."timestamp_ops" ASC NULLS LAST
);
CREATE INDEX "kline_5m_symbol_time_idx" ON "public"."kline_5m" USING btree (
  "symbol" COLLATE "pg_catalog"."default" "pg_catalog"."text_ops" ASC NULLS LAST,
  "open_time" "pg_catalog"."timestamp_ops" ASC NULLS LAST
);

-- ----------------------------
-- Uniques structure for table kline_5m
-- ----------------------------
ALTER TABLE "public"."kline_5m" ADD CONSTRAINT "kline_5m_symbol_open_time_key" UNIQUE ("symbol", "open_time");

-- ----------------------------
-- Primary Key structure for table kline_5m
-- ----------------------------
ALTER TABLE "public"."kline_5m" ADD CONSTRAINT "kline_5m_pkey" PRIMARY KEY ("id", "open_time", "symbol");


-- ----------------------------
-- Table structure for kline_15m
-- ----------------------------
DROP TABLE IF EXISTS "public"."kline_15m";
CREATE TABLE "public"."kline_15m" (
  "id" bigserial NOT NULL,
  "symbol" text COLLATE "pg_catalog"."default" NOT NULL,
  "open" numeric(20,10) NOT NULL,
  "high" numeric(20,10) NOT NULL,
  "low" numeric(20,10) NOT NULL,
  "close" numeric(20,10) NOT NULL,
  "volume" numeric(28,12) NOT NULL,
  "amount" numeric(28,12) NOT NULL,
  "num_trades" int8 NOT NULL,
  "buy_volume" numeric(28,12) NOT NULL,
  "buy_amount" numeric(28,12) NOT NULL,
  "open_time" timestamp(6) NOT NULL,
  "close_time" timestamp(6) NOT NULL,
  "boll_up" numeric(20,10),
  "boll_mb" numeric(20,10),
  "boll_dn" numeric(20,10),
  "bw" numeric(20,10),
  "mouth_state" int2 NOT NULL DEFAULT 0,
  "is_check" int2 NOT NULL DEFAULT 0,
  "is_key" int2 NOT NULL DEFAULT 0
)
;

-- ----------------------------
-- Indexes structure for table kline_15m
-- ----------------------------
CREATE INDEX "kline_15m_open_time_idx" ON "public"."kline_15m" USING btree (
  "open_time" "pg_catalog"."timestamp_ops" DESC NULLS FIRST
);
CREATE INDEX "kline_15m_symbol_check_time_idx" ON "public"."kline_15m" USING btree (
  "symbol" COLLATE "pg_catalog"."default" "pg_catalog"."text_ops" ASC NULLS LAST,
  "is_check" "pg_catalog"."int2_ops" ASC NULLS LAST,
  "open_time" "pg_catalog"."timestamp_ops" ASC NULLS LAST
);
CREATE INDEX "kline_15m_symbol_time_idx" ON "public"."kline_15m" USING btree (
  "symbol" COLLATE "pg_catalog"."default" "pg_catalog"."text_ops" ASC NULLS LAST,
  "open_time" "pg_catalog"."timestamp_ops" ASC NULLS LAST
);

-- ----------------------------
-- Uniques structure for table kline_15m
-- ----------------------------
ALTER TABLE "public"."kline_15m" ADD CONSTRAINT "kline_15m_symbol_open_time_key" UNIQUE ("symbol", "open_time");

-- ----------------------------
-- Primary Key structure for table kline_15m
-- ----------------------------
ALTER TABLE "public"."kline_15m" ADD CONSTRAINT "kline_15m_pkey" PRIMARY KEY ("id", "open_time", "symbol");


-- ----------------------------
-- Table structure for kline_30m
-- ----------------------------
DROP TABLE IF EXISTS "public"."kline_30m";
CREATE TABLE "public"."kline_30m" (
  "id" bigserial NOT NULL,
  "symbol" text COLLATE "pg_catalog"."default" NOT NULL,
  "open" numeric(20,10) NOT NULL,
  "high" numeric(20,10) NOT NULL,
  "low" numeric(20,10) NOT NULL,
  "close" numeric(20,10) NOT NULL,
  "volume" numeric(28,12) NOT NULL,
  "amount" numeric(28,12) NOT NULL,
  "num_trades" int8 NOT NULL,
  "buy_volume" numeric(28,12) NOT NULL,
  "buy_amount" numeric(28,12) NOT NULL,
  "open_time" timestamp(6) NOT NULL,
  "close_time" timestamp(6) NOT NULL,
  "boll_up" numeric(20,10),
  "boll_mb" numeric(20,10),
  "boll_dn" numeric(20,10),
  "bw" numeric(20,10),
  "mouth_state" int2 NOT NULL DEFAULT 0,
  "is_check" int2 NOT NULL DEFAULT 0,
  "is_key" int2 NOT NULL DEFAULT 0
)
;

-- ----------------------------
-- Indexes structure for table kline_30m
-- ----------------------------
CREATE INDEX "kline_30m_open_time_idx" ON "public"."kline_30m" USING btree (
  "open_time" "pg_catalog"."timestamp_ops" DESC NULLS FIRST
);
CREATE INDEX "kline_30m_symbol_check_time_idx" ON "public"."kline_30m" USING btree (
  "symbol" COLLATE "pg_catalog"."default" "pg_catalog"."text_ops" ASC NULLS LAST,
  "is_check" "pg_catalog"."int2_ops" ASC NULLS LAST,
  "open_time" "pg_catalog"."timestamp_ops" ASC NULLS LAST
);
CREATE INDEX "kline_30m_symbol_time_idx" ON "public"."kline_30m" USING btree (
  "symbol" COLLATE "pg_catalog"."default" "pg_catalog"."text_ops" ASC NULLS LAST,
  "open_time" "pg_catalog"."timestamp_ops" ASC NULLS LAST
);

-- ----------------------------
-- Uniques structure for table kline_30m
-- ----------------------------
ALTER TABLE "public"."kline_30m" ADD CONSTRAINT "kline_30m_symbol_open_time_key" UNIQUE ("symbol", "open_time");

-- ----------------------------
-- Primary Key structure for table kline_30m
-- ----------------------------
ALTER TABLE "public"."kline_30m" ADD CONSTRAINT "kline_30m_pkey" PRIMARY KEY ("id", "open_time", "symbol");


-- ----------------------------
-- Table structure for kline_1h
-- ----------------------------
DROP TABLE IF EXISTS "public"."kline_1h";
CREATE TABLE "public"."kline_1h" (
  "id" bigserial NOT NULL,
  "symbol" text COLLATE "pg_catalog"."default" NOT NULL,
  "open" numeric(20,10) NOT NULL,
  "high" numeric(20,10) NOT NULL,
  "low" numeric(20,10) NOT NULL,
  "close" numeric(20,10) NOT NULL,
  "volume" numeric(28,12) NOT NULL,
  "amount" numeric(28,12) NOT NULL,
  "num_trades" int8 NOT NULL,
  "buy_volume" numeric(28,12) NOT NULL,
  "buy_amount" numeric(28,12) NOT NULL,
  "open_time" timestamp(6) NOT NULL,
  "close_time" timestamp(6) NOT NULL,
  "boll_up" numeric(20,10),
  "boll_mb" numeric(20,10),
  "boll_dn" numeric(20,10),
  "bw" numeric(20,10),
  "mouth_state" int2 NOT NULL DEFAULT 0,
  "is_check" int2 NOT NULL DEFAULT 0,
  "is_key" int2 NOT NULL DEFAULT 0
)
;

-- ----------------------------
-- Indexes structure for table kline_1h
-- ----------------------------
CREATE INDEX "kline_1h_open_time_idx" ON "public"."kline_1h" USING btree (
  "open_time" "pg_catalog"."timestamp_ops" DESC NULLS FIRST
);
CREATE INDEX "kline_1h_symbol_check_time_idx" ON "public"."kline_1h" USING btree (
  "symbol" COLLATE "pg_catalog"."default" "pg_catalog"."text_ops" ASC NULLS LAST,
  "is_check" "pg_catalog"."int2_ops" ASC NULLS LAST,
  "open_time" "pg_catalog"."timestamp_ops" ASC NULLS LAST
);
CREATE INDEX "kline_1h_symbol_time_idx" ON "public"."kline_1h" USING btree (
  "symbol" COLLATE "pg_catalog"."default" "pg_catalog"."text_ops" ASC NULLS LAST,
  "open_time" "pg_catalog"."timestamp_ops" ASC NULLS LAST
);

-- ----------------------------
-- Uniques structure for table kline_1h
-- ----------------------------
ALTER TABLE "public"."kline_1h" ADD CONSTRAINT "kline_1h_symbol_open_time_key" UNIQUE ("symbol", "open_time");

-- ----------------------------
-- Primary Key structure for table kline_1h
-- ----------------------------
ALTER TABLE "public"."kline_1h" ADD CONSTRAINT "kline_1h_pkey" PRIMARY KEY ("id", "open_time", "symbol");


-- ----------------------------
-- Table structure for kline_4h
-- ----------------------------
DROP TABLE IF EXISTS "public"."kline_4h";
CREATE TABLE "public"."kline_4h" (
  "id" bigserial NOT NULL,
  "symbol" text COLLATE "pg_catalog"."default" NOT NULL,
  "open" numeric(20,10) NOT NULL,
  "high" numeric(20,10) NOT NULL,
  "low" numeric(20,10) NOT NULL,
  "close" numeric(20,10) NOT NULL,
  "volume" numeric(28,12) NOT NULL,
  "amount" numeric(28,12) NOT NULL,
  "num_trades" int8 NOT NULL,
  "buy_volume" numeric(28,12) NOT NULL,
  "buy_amount" numeric(28,12) NOT NULL,
  "open_time" timestamp(6) NOT NULL,
  "close_time" timestamp(6) NOT NULL,
  "boll_up" numeric(20,10),
  "boll_mb" numeric(20,10),
  "boll_dn" numeric(20,10),
  "bw" numeric(20,10),
  "mouth_state" int2 NOT NULL DEFAULT 0,
  "is_check" int2 NOT NULL DEFAULT 0,
  "is_key" int2 NOT NULL DEFAULT 0
)
;

-- ----------------------------
-- Indexes structure for table kline_4h
-- ----------------------------
CREATE INDEX "kline_4h_open_time_idx" ON "public"."kline_4h" USING btree (
  "open_time" "pg_catalog"."timestamp_ops" DESC NULLS FIRST
);
CREATE INDEX "kline_4h_symbol_check_time_idx" ON "public"."kline_4h" USING btree (
  "symbol" COLLATE "pg_catalog"."default" "pg_catalog"."text_ops" ASC NULLS LAST,
  "is_check" "pg_catalog"."int2_ops" ASC NULLS LAST,
  "open_time" "pg_catalog"."timestamp_ops" ASC NULLS LAST
);
CREATE INDEX "kline_4h_symbol_time_idx" ON "public"."kline_4h" USING btree (
  "symbol" COLLATE "pg_catalog"."default" "pg_catalog"."text_ops" ASC NULLS LAST,
  "open_time" "pg_catalog"."timestamp_ops" ASC NULLS LAST
);

-- ----------------------------
-- Uniques structure for table kline_4h
-- ----------------------------
ALTER TABLE "public"."kline_4h" ADD CONSTRAINT "kline_4h_symbol_open_time_key" UNIQUE ("symbol", "open_time");

-- ----------------------------
-- Primary Key structure for table kline_4h
-- ----------------------------
ALTER TABLE "public"."kline_4h" ADD CONSTRAINT "kline_4h_pkey" PRIMARY KEY ("id", "open_time", "symbol");
