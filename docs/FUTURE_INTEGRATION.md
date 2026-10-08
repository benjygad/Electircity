# Future Meter Integration Design

SEMS is built so that a **real, authorized** TANESCO/LUKU interface — or a
compatible hardware gateway — can be added **without redesigning the system**.

## Current state (honest baseline)

- No integration with TANESCO systems exists, and none is claimed.
- The only active gateway is `SimulatedGateway` (`backend/src/MeterIntegration/`).
- `LukuGateway` exists as an **inert placeholder**: it reports
  `integration_unavailable` and refuses to transmit tokens anywhere.
- Every reading/transaction row carries a `source` label, so real data can
  never be confused with simulated data later.

## Integration contract

```
MeterGatewayInterface
 ├─ name(): string                     e.g. 'luku_authorized'
 ├─ isLive(): bool                     true only for verified integrations
 ├─ fetchLatestReading(meterId, meterNumber): ?Reading
 └─ submitToken(meterId, meterNumber, maskedRef): {status, detail}
```

`GatewayFactory` selects the implementation from configuration
(`METER_GATEWAY=luku`). All controllers depend on the interface, never on a
concrete vendor class.

## Enabling a live integration — required steps

1. **Authorization**: obtain written authorization and technical
   specifications from the utility (or accredited vendor). Nothing in SEMS
   should call an undocumented or scraped utility endpoint.
2. **Adapter implementation**: implement `MeterGatewayInterface` against the
   official interface (API/HSM/STS-style token issuing, meter polling, tamper
   flags). Credentials go in `.env` (never in code or frontends).
3. **Honest status mapping**:
   - readings ingested from the live interface → `source='hardware'`;
   - meters reporting live → `integration_status='connected'`;
   - token submissions return real confirmation statuses; UI wording switches
     from “simulated” to “confirmed by utility” only on genuine confirmation.
4. **Hardware gateway option**: for smart-meter/sensor deployments, a small
   gateway device can POST readings to `POST /meters/{id}/readings` using a
   service account token; tamper events map to `tamper_event` alerts.
5. **Rollback safety**: because historical rows are append-only and labelled,
   switching gateways never corrupts past data; dashboards filter by source.

## Future roles

The `users.role` enum already reserves `technician` and `support`. Adding
restricted dashboards for them means defining their route allow-lists in the
router (server-side), not touching the data model.

## Future smart-home features

Device registration tables and a `DeviceGatewayInterface` can follow the same
pattern. Any simulated device control in prototypes must be explicitly
labelled; real control requires verified hardware integration and explicit
user consent.
