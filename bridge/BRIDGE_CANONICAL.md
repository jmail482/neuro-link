# Bridge Stack — Canonical Reference
# Written: 2026-03-13

## Front Door
- Public URL : https://bridge.winnipeglocalseo.ca
- Tunnel target : http://127.0.0.1:8785 (triadapter)
- Config : C:\Users\jfnfi\.cloudflared\config.yml

## Port Map
| Port | Role         | Script                                              | Version |
|------|--------------|-----------------------------------------------------|---------|
| 8784 | State hub    | C:\Users\jfnfi\Documents\AI\Zeus\bridge_state_hub.py     | 1.0.0   |
| 8785 | Triadapter   | C:\Users\jfnfi\Documents\AI\Zeus\bridge_triadapter.py    | 1.0.0   |
| 8786 | Router       | C:\Users\jfnfi\Documents\AI\Zeus\bridge_router.py        | 1.0.0   |
| 8787 | Bridge A     | C:\Users\jfnfi\Documents\AI\Zeus\wp_bridge_local.py                | 1.5.0   |
| 8790 | Bridge B     | C:\Users\jfnfi\Documents\AI\Zeus\bridge_b.py             | 1.5.0-b |

## Canonical Bridge A
- Path    : C:\Users\jfnfi\Documents\AI\Zeus\wp_bridge_local.py
- Version : 1.5.0
- This is the ONLY active bridge A. Do not use the Neuro Link copy.

## Archived / Dead
- C:\Users\jfnfi\Documents\AI\Projects\Neuro Link\archive\wp_bridge_local.v2.0.ARCHIVED.py
  Reason: orphaned v2.0 rewrite, never wired into stack, superseded by v1.5.0 in Laragon.

## Launcher
- Full stack start : C:\Users\jfnfi\Documents\AI\Zeus\start_bridge_stack_full.ps1
- Watchdog/supervisor : C:\Users\jfnfi\Documents\AI\Zeus\watch-bridges.ps1
  (supervised by Zeus-Watchdog scheduled task via zeus_watchdog.ps1)

## Notes
- 8787 and 8790 show alive=false in triadapter health probe because they respond
  on /health not /. Functionally healthy — confirmed auth_configured=true on both.
- Triadapter active_backend routes through 8786 (router) -> 8787 (bridge A).

