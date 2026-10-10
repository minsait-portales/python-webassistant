#!/bin/sh
# DAST con OWASP ZAP (escaneo de API desde el contrato OpenAPI) contra el stack de Compose (CG-15.10).
#   scripts/dast.sh [anon|server|admin] [directorio_de_informes]
# server usa el cliente API de demo (ingesta y búsqueda); admin, el administrador de demo.
# Solo en entornos de validación: el escaneo activo envía cargas hostiles y modifica datos.
set -eu
MODE=${1:-anon}
OUT=${2:-docs/pruebas/evidencias}
API=${API_URL:-http://localhost:${WEBASSISTANT_PORT:-8000}}
COMPOSE=${COMPOSE:-docker compose}
NETWORK=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}' "$($COMPOSE ps -q api)")
mkdir -p "$OUT/zap"
chmod 0777 "$OUT/zap"

case "$MODE" in
  server)
    TOKEN=$(curl -fsS -X POST "$API/v1/token" -H 'Content-Type: application/json' \
      -d "{\"grant_type\":\"client_credentials\",\"client_id\":\"${CLIENT_ID:-wa_demo_portal}\",\"client_secret\":\"${CLIENT_SECRET:-demo-portal-secret-change-me}\"}" \
      | python3 -c 'import json,sys; print(json.load(sys.stdin)["access_token"])') ;;
  admin)
    TOKEN=$(curl -fsS -X POST "$API/v1/admin/auth/login" -H 'Content-Type: application/json' \
      -d "{\"email\":\"${ADMIN_EMAIL:-admin@demo.local}\",\"password\":\"${ADMIN_PASSWORD:-Demo-Admin-2026}\"}" \
      | python3 -c 'import json,sys; print(json.load(sys.stdin)["access_token"])') ;;
  *) TOKEN="" ;;
esac

rm -f "$OUT/zap/dast-$MODE.json" "$OUT/zap/dast-$MODE.html"
status=0
docker run --rm --network "$NETWORK" \
  -v "$(cd "$OUT/zap" && pwd):/zap/wrk:rw" -v "$(pwd)/.zap:/zap/rules:ro" \
  ${TOKEN:+-e ZAP_AUTH_HEADER_VALUE="Bearer $TOKEN"} \
  zaproxy/zap-stable:2.16.1 zap-api-scan.py \
  -t http://api:8000/v1/openapi.json -f openapi \
  -c /zap/rules/rules.tsv -J "dast-$MODE.json" -r "dast-$MODE.html" -I || status=$?
if [ ! -s "$OUT/zap/dast-$MODE.json" ]; then
  echo "ZAP no generó el informe (código $status)" >&2
  exit 1
fi

# Criterio de CG-15.10: cero hallazgos altos (riskcode 3). Los medios/bajos se revisan en el informe.
# El informe completo (zap/) no se versiona; el resumen dast-<modo>.json sí.
python3 - "$OUT/zap/dast-$MODE.json" "$OUT/dast-$MODE.json" "$MODE" <<'PY'
import json, sys
from collections import Counter
with open(sys.argv[1], encoding="utf-8") as fh:
    report = json.load(fh)
alerts = [a for site in report.get("site", []) for a in site.get("alerts", [])]
risks = Counter(int(a["riskcode"]) for a in alerts)
summary = {
    "tool": f"OWASP ZAP {report.get('@version', '')} zap-api-scan",
    "generated": report.get("@generated"),
    "mode": sys.argv[3],
    "totals": {"high": risks[3], "medium": risks[2], "low": risks[1], "informational": risks[0]},
    "alerts": [
        {
            "id": a["pluginid"],
            "alert": a["alert"],
            "risk": a["riskdesc"],
            "count": int(a["count"]),
            "examples": [f"{i['method']} {i['uri']}" for i in a["instances"][:3]],
        }
        for a in alerts
    ],
}
with open(sys.argv[2], "w", encoding="utf-8") as fh:
    fh.write(json.dumps(summary, ensure_ascii=False, indent=2) + "\n")
print(f"ZAP: altos={risks[3]} medios={risks[2]} bajos={risks[1]} informativos={risks[0]}")
for a in alerts:
    if int(a["riskcode"]) >= 1:
        print(f"  [{a['riskdesc']}] {a['alert']} ({a['count']})")
sys.exit(1 if risks[3] else 0)
PY
