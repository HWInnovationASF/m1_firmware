# PHP EMS decision engine (2026-11-03)

Automation now evaluates the supplied PHP functions in `python/ems_decision.php`
through `ems_policy.py`. It replaces the previous state/ramp rules and the
separate approximate reference calculation. PHP CLI is a runtime dependency.
The engine reads JSON on stdin and emits JSON plus a decision trace. It has no
network, MQTT or file-writing functions. Python owns all actual transport.

`automation_control.json` remains canonical. `EMS_Config`, `EMS_Strategy`, and
inverter ratings are passed directly to the engine. M1 device permissions,
register availability and simulation mode remain enforced. The current site
was deployed with simulation enabled and BESS device control disabled.

The supplied `main_function.php` was not included. Holiday/off-peak decisions
use M1's existing calendar (default weekdays 09:00-22:00 Bangkok). Matching an
external PHP installation requires the same calendar and input snapshot.
Live M1 telemetry is used instead of the original remote five-minute log API.
EV telemetry is read from the configured SID, cached for five seconds. If EV
telemetry cannot be read, Peak Shaving is skipped with an explicit log message;
TOU/ABF still run. EV writes additionally require existing EV device permission
or explicit `EMS_Config.EV.allow_control`; simulation never sends EV commands.

The source's post-send `cmd_pt_bess=0` override for measured BESS power between
-3 and +3 kW was removed. Output now describes the requested command, while
outgoing integer rounding follows PHP. All other function decisions are retained,
including source quirks such as two-stage solar release allocation.

`[PHP EMS OUTPUT]` and the compatibility `[PHP REFERENCE OUTPUT]` label now share
one result. `[EMS BLOCKED]` identifies proposals blocked at the execution boundary.
Simulation logs and Automation-vs-MQTT history retain their existing format.

`?page=ems-live-output` is an Automation-only execution view. Its `cmd_*` fields
and `M1 Actual Commands` contain only writes successfully issued by M1 in live
mode. Incoming MQTT commands never enter this stream. In simulation, calculated
proposals remain in the decision trace while actual commands are always empty.

Offline verification (extract python.7z into this repository first):

```
python tests/test_ems_php_parity.py
python tests/test_ems_adapter.py
```

The parity suite evaluates 186 boundary/randomized cases against the original
supplied functions with all network and logging functions stubbed. It compares
returned fields and captured outgoing MQTT commands. Adapter tests verify
calendar mapping, missing telemetry, permissions and simulation write blocking.
