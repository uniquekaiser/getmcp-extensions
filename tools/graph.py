"""Portable, code-only Graphify AST map. No provider credentials or model/API calls."""
from pathlib import Path
import json,subprocess,sys
ROOT=Path(__file__).resolve().parents[1]
subprocess.run(['graphify','extract',str(ROOT),'--code-only','--no-cluster'],cwd=ROOT,check=True)
graph=ROOT/'graphify-out/graph.json'
# AST locations must remain portable across clones; sidecars with machine paths are ignored.
data=graph.read_text(encoding='utf-8').replace(str(ROOT).replace('\\','/'),'').replace(str(ROOT).replace('\\','\\\\'),'')
graph.write_text(data,encoding='utf-8')
subprocess.run(['graphify','cluster-only',str(ROOT),'--no-label'],cwd=ROOT,check=True)
subprocess.run(['graphify','export','html'],cwd=ROOT,check=True)
report=ROOT/'graphify-out/GRAPH_REPORT.md'
report.write_text(report.read_text(encoding='utf-8').replace('model-reasoned connections','AST-inferred connections'),encoding='utf-8')
print('Code-only Graphify map complete. Semantic documentation extraction was not run; model token cost: 0.')
