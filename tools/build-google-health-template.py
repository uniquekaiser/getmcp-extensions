"""Build the fixed, read-only native Google Health template. No HTTP or credentials."""
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]
BASE = 'https://health.googleapis.com/v4/users/me'
scopes = ['profile', 'settings', 'activity_and_fitness', 'health_metrics_and_measurements', 'sleep', 'nutrition']
auth = dict(provider='external', authorize_url='https://accounts.google.com/o/oauth2/v2/auth',
            token_url='https://oauth2.googleapis.com/token', client_id='',
            scope=' '.join('https://www.googleapis.com/auth/googlehealth.' + s + '.readonly' for s in scopes),
            extra_authorize_params=dict(access_type='offline', prompt='consent', include_granted_scopes='true'))

def string(description='', **kwargs):
    return dict(type='string', description=description, **kwargs)

page = dict(page_size=dict(type='integer', minimum=1, maximum=100, description='Maximum points per page. Sleep is capped at 25.'),
            page_token=string('Opaque nextPageToken from the previous response; keep all other inputs identical.', maxLength=2048))
family = dict(data_source_family=string('Optional source family.', enum=['users/me/dataSourceFamilies/' + x for x in ['all-sources', 'google-wearables', 'google-sources', 'self-sources']]))
types = ['steps', 'distance', 'total-calories', 'active-zone-minutes', 'heart-rate', 'daily-resting-heart-rate', 'daily-heart-rate-variability', 'weight', 'body-fat', 'sleep', 'nutrition-log', 'hydration-log', 'food', 'food-measurement-unit']
type_prop = dict(data_type=string('Curated data type. Consult the inventory for supported operations.', enum=types))
filter_prop = dict(filter=string('Google AIP-160 filter. Sleep uses sleep.interval.civil_end_time; daily metrics use their snake_case date field.', maxLength=2048))
tools = []

def tool(suffix, description, endpoint, method='GET', props=None, required=None, mapping=None):
    props = props or {}
    tools.append(dict(name='google_health_' + suffix, slug='google_health_' + suffix, description=description,
                      endpoint_url=BASE + endpoint, http_method=method, tool_type='http', handler='',
                      input_schema=dict(type='object', properties=props, required=required or [], additionalProperties=False),
                      parameter_mapping=mapping or {}, body_template=None, response_mapping=None,
                      headers={'Accept': 'application/json', 'Content-Type': 'application/json'}, timeout=30,
                      cache_ttl=0, retry_count=0, retry_backoff='exponential', ssl_verify=True,
                      rate_limit_per_min=0, status='active', tags=['google-health', 'read-only'],
                      annotations=dict(readOnlyHint=True, destructiveHint=False, idempotentHint=True, openWorldHint=True),
                      sort_order=len(tools)))

def mapping(props, method='GET'):
    names = dict(page_size='pageSize', page_token='pageToken', data_source_family='dataSourceFamily',
                 window_size_days='windowSizeDays', window_size='windowSize')
    return {k:dict(target='path' if k == 'data_type' else ('query' if method == 'GET' else 'body'), key=names.get(k,k))
            for k in props if k not in ['start_date','end_date','start_time','end_time']}

for name in ['identity','profile','settings']:
    tool('get_' + name, 'Read authorized Google Health ' + name + '. Identity and location fields are filtered.', '/' + name)
for op, endpoint in [('list_data_points',''),('reconcile_data_points',':reconcile')]:
    props = type_prop | page | filter_prop | family
    tool(op, 'Read one page of Google Health data. Follow nextPageToken explicitly. No data is unknown, never zero.',
         '/dataTypes/{{data_type}}/dataPoints' + endpoint, props=props, required=['data_type'], mapping=mapping(props))
props = type_prop | page | family | dict(start_date=string('Inclusive civil YYYY-MM-DD.'), end_date=string('Exclusive civil YYYY-MM-DD.'), window_size_days=dict(type='integer', minimum=1, maximum=90))
tool('daily_rollup', 'Read daily aggregates. Valid civil date range required; data-type limits apply. This POST is read-only.', '/dataTypes/{{data_type}}/dataPoints:dailyRollUp', 'POST', props, ['data_type','start_date','end_date'], mapping(props,'POST'))
props = type_prop | page | family | dict(start_time=string('Inclusive RFC3339 timestamp with timezone.'), end_time=string('Exclusive RFC3339 timestamp with timezone.'), window_size=string('Positive duration, e.g. 86400s.'))
tool('rollup', 'Read aggregates over exact timestamps, at most 14 days. This POST is read-only.', '/dataTypes/{{data_type}}/dataPoints:rollUp', 'POST', props, ['data_type','start_time','end_time','window_size'], mapping(props,'POST'))
props = page | filter_prop | family
tool('nutrition', 'Read one page of nutrition-log records if nutrition.readonly is granted and the account has data. Does not write food logs.', '/dataTypes/nutrition-log/dataPoints', props=props, mapping=mapping(props) | dict(data_type=dict(target='path',key='data_type')))
for name in ['daily_summary','weekly_summary']:
    tool(name, 'Observed daily health metrics including available nutrition/hydration. Weekly covers seven civil dates ending on date. Returns per-domain missing scopes, errors and pagination completeness; no medical inference.', '/profile', props=dict(date=string('Daily date, or final date of the seven-day summary. YYYY-MM-DD; defaults to today in timezone.'), timezone=string('IANA timezone, e.g. America/Bogota. Defaults to the WordPress timezone.')))
tool('data_inventory', 'Read the curated local data-type/operation/scope inventory. Does not probe your account.', '/profile')
tool('connection_status', 'Read personal connection and granted-scope status without exposing credentials. Does not prove a live data read.', '/profile')

data = dict(format='getmcp/server-v1', server=dict(name='Google Health', slug='google-health', status='draft', server_kind='native', transport_type='streamable-http', auth_type='oauth', auth_config=auth,
            settings=dict(google_health=True, getmcp_extensions_required=True, instructions='Read-only Google Health API v4, including available nutrition data. Configure a Google web OAuth application; allow personal users and connect in My MCP Connections. Publish through a WordPress OAuth project gateway. Summaries report observed civil-day metrics and explicit gaps; no medical assessment. Health payload caching and replay are disabled.')),
            tools=tools)
(ROOT/'assets/templates/google-health.json').write_text(json.dumps(data, indent=2)+'\n', encoding='utf-8')
print(f'Google Health template: {len(tools)} read-only tools.')
