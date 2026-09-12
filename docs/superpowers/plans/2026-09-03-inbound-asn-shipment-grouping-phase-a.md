# Inbound ASN Shipment Grouping — Phase A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or executing-plans. Steps use checkbox syntax.

**Goal:** Group inbound EPCIS docs by seller+ASN into `inbound_shipments`; union expected parents for one receive session; soft-signal when a second file joins.

**Architecture:** Attach-on-enrich creates/joins shipment; `OpenReceivingSessionFromDocument` reuses session by `inbound_shipment_id` and seeds roots from all member docs; late attach expands expected lines.

**Tech Stack:** Laravel 13, tenant migrations, Pest/PHPUnit, existing receiving + exception catalog patterns.

**Spec:** `docs/superpowers/specs/2026-09-03-inbound-asn-shipment-grouping-design.md`

## Tasks

- [x] Tenant migration: `inbound_shipments`, `epcis_documents.inbound_shipment_id`, `receiving_sessions.inbound_shipment_id`
- [x] Model `InboundShipment` + relations
- [x] Catalog: `ASN_SHIPMENT_FILE_ADDED` (warning) in seeder/maps/profile
- [x] `AttachInboundDocumentToShipment` + call from enrich; expand open session expected parents
- [x] `OpenReceivingSessionFromDocument` reuse by shipment + union roots
- [x] Backfill command
- [x] Feature tests for attach, union receive, late file, PO mismatch
