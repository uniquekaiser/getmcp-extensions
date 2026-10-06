"""Generate the WordPress changelog from the single categorized canonical source."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]
out=[]
for line in (root/'CHANGELOG.md').read_text(encoding='utf-8').splitlines():
 if match:=re.match(r'## (\d+\.\d+\.\d+)\b',line):out.extend(['= '+match.group(1)+' =',''])
 elif line.startswith('- ['):out.append('* '+line[2:])
 elif not line.strip() and out and out[-1]:out.append('')
readme=root/'readme.txt';text=readme.read_text(encoding='utf-8')
start=text.index('== Changelog ==');end=text.index('== Upgrade Notice ==')
readme.write_text(text[:start]+'== Changelog ==\n\n'+'\n'.join(out).rstrip()+'\n\n'+text[end:],encoding='utf-8',newline='\n')
