"""Import literal catalogue data, never execute the reference TypeScript/scripts."""
import argparse, ast, hashlib, ipaddress, json, re
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

ROOT = Path(__file__).resolve().parents[1]
FIELDS = ('id','name','endpoint','auth','category','description','docs','tokenHint','listing')

def literal_entries(path, variable):
    text = path.read_text(encoding='utf-8-sig')
    start = re.search(r'\b' + variable + r'\s*:[^=]+?=\s*\[', text)
    if not start: raise ValueError('Missing literal array: ' + variable)
    text = text[start.end():].split('\n];', 1)[0]
    result = []
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith('//'): continue
        if not line.startswith('{ ') or not line.endswith('},'): raise ValueError('Only literal catalogue rows are supported.')
        body = line[1:-2].strip(); entry = {}
        while body:
            m = re.match(r"(\w+)\s*:\s*('(?:\\.|[^'\\])*')\s*(?:,\s*|$)", body)
            if not m or m[1] not in FIELDS or m[1] in entry: raise ValueError('Unsupported catalogue expression.')
            entry[m[1]] = ast.literal_eval(m[2]); body = body[m.end():]
        result.append(entry)
    return result

def public_url(value):
    p = urlsplit(value)
    if p.scheme != 'https' or not p.hostname or p.username or p.password or p.query or p.fragment or p.port not in (None,443): raise ValueError('Public HTTPS URL without credentials, query or fragment required.')
    if any(c in value for c in '{}<>\\\r\n') or p.hostname in {'localhost','example.com','example.org','example-server.modelcontextprotocol.io'} or p.hostname.endswith(('.local','.test','.invalid','.example')): raise ValueError('Local, placeholder or demo endpoint.')
    try: ipaddress.ip_address(p.hostname); raise ValueError('Literal IP endpoints excluded.')
    except ValueError as e:
        if str(e) == 'Literal IP endpoints excluded.': raise
    if p.path.rstrip('/').endswith('/sse'): raise ValueError('Legacy SSE endpoint requires separate review.')
    return urlunsplit(('https',p.hostname,p.path.rstrip('/'),'',''))

def build(sources):
    entries=[]; excluded=[]; ids=set(); endpoints=set()
    for source, rows in sources:
        for row in rows:
            try:
                auth = row.get('auth',row.get('check'))
                if auth not in {'none','bearer','oauth'}: raise ValueError('Unsupported or unverified authentication/transport.')
                key=public_url(row['endpoint'])
                slug=row.get('id') or re.sub('[^a-z0-9-]','-',row.get('slug','').split('/')[-1].lower())
                if not re.fullmatch('[a-z0-9-]{1,100}',slug): raise ValueError('Invalid catalogue ID.')
                if slug in ids or key in endpoints: continue
                ids.add(slug);endpoints.add(key)
                item={k:row[k] for k in FIELDS if k in row};item.update(id=slug,auth=auth,source=source,verification='reference-snapshot')
                for k in ['name','description','category']:item[k]=str(item.get(k,''))[:500]
                for k in ['docs','listing']:
                    if k in item:
                        p=urlsplit(item[k])
                        if p.scheme!='https' or not p.hostname or p.username or p.password: item.pop(k)
                entries.append(item)
            except (ValueError,KeyError) as error: excluded.append({'id':row.get('id',row.get('slug','unknown')),'reason':str(error)})
    return entries, excluded

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--reference',type=Path,help='MCP-proxy root');ap.add_argument('--checked-json',type=Path,help='Reviewed official candidates with check=none/oauth/bearer');args=ap.parse_args()
    sources=[];hashes={}
    if args.reference:
        for name,var,label in [('catalogue.ts','CURATED','curated-reference'),('catalogue-official.ts','OFFICIAL','official-reference')]:
            p=args.reference/'src/upstream'/name;hashes[name]=hashlib.sha256(p.read_bytes()).hexdigest();sources.append((label,literal_entries(p,var)))
    if args.checked_json:
        hashes[args.checked_json.name]=hashlib.sha256(args.checked_json.read_bytes()).hexdigest();sources.append(('reviewed-official-import',json.loads(args.checked_json.read_text())))
    if not sources:ap.error('Provide --reference or --checked-json')
    items,excluded=build(sources)
    data={'schema':1,'reference_checked':'2026-10-09' if args.reference else None,'source_hashes':hashes,'verification_notice':'Reference snapshot, not a current hosting/authentication guarantee. Test each connection before activation.','items':items,'excluded':excluded}
    dest=ROOT/'assets/remote-catalogue.json';dest.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(json.dumps({'included':len(items),'excluded':excluded,'auth':{a:sum(i['auth']==a for i in items) for a in ['none','oauth','bearer']}}))
if __name__=='__main__':main()
