"""Generate disabled review fragments from an exact fresh baseline; never install."""
import argparse
import datetime as dt
import ipaddress
import json
from pathlib import Path
import re


def render(config: dict, baseline: dict, now: float) -> dict[str, str]:
    if (config.get('enabled') is not False or baseline.get('schema') != 1
            or not re.fullmatch(r'3\.8\.[0-9]+', baseline.get('mail_version', ''))
            or baseline.get('queue_directory') != '/var/spool/postfix'
            or baseline.get('maximal_queue_lifetime_seconds') != 5*86400
            or baseline.get('pilotAliasAndBccMappingAbsent') is not True):
        raise ValueError('E3_BASELINE_REVIEW_REQUIRED')
    for stamp in (config.get('networkObservedAtUtc', ''), baseline.get('observedAtUtc', '')):
        observed = dt.datetime.fromisoformat(stamp.replace('Z', '+00:00')).timestamp()
        if not 0 <= now-observed <= 7*86400:
            raise ValueError('E3_FRESH_BASELINE_REQUIRED')
    if baseline.get('smtpd_milter_maps') or baseline.get('milter_header_checks'):
        raise ValueError('E3_EXISTING_MAP_MERGE_REQUIRES_SEPARATE_REVIEW')
    milters = baseline.get('smtpd_milters', '')
    if not milters or any(v in milters for v in '\r\n{}'):
        raise ValueError('E3_EXISTING_MILTER_BASELINE_REQUIRED')
    # Plesk's observed leading empty comma element is not an effective Milter.
    # Preserve every nonempty endpoint and its order, without inventing one.
    milters = ', '.join(item.strip() for item in milters.split(',') if item.strip())
    restrictions = baseline.get('smtpd_relay_restrictions', '')
    tokens = [s for s in re.split(r'[,\s]+', restrictions) if s]
    if tokens != ['permit_mynetworks', 'permit_sasl_authenticated', 'defer_unauth_destination']:
        raise ValueError('E3_RELAY_RESTRICTION_MERGE_REQUIRED')
    merged = ', '.join(tokens[:-1]+['check_recipient_access hash:/etc/postfix/railtime-e3/pilot-rcpt', tokens[-1]])
    networks = [str(ipaddress.ip_network(s)) for s in config.get('microsoftNetworks', [])]
    if not networks or any(ipaddress.ip_network(s).prefixlen == 0 for s in networks):
        raise ValueError('E3_EXACT_SMTP_NETWORKS_REQUIRED')
    optional = '{ unix:railtime-e3/ingress.sock, default_action=tempfail, protocol=6, connect_timeout=5s, command_timeout=30s, content_timeout=30s }'
    output = {
        'main.cf.merge.review': ('# REVIEW ONLY. Preserve all other current Plesk settings.\n'
            +'smtpd_relay_restrictions = '+merged+'\n'
            +'smtpd_milter_maps = cidr:/etc/postfix/railtime-e3/milter-map\n'
            +'milter_header_checks = pcre:/etc/postfix/railtime-e3/milter-header-checks\n'
            +'railtime-filter_destination_recipient_limit = 1\n'
            +'# Global milter_default_action and global content_filter remain baseline.\n'),
        'pilot-rcpt.review': 'it-technik@rail-time.de check_policy_service unix:railtime-e3/policy.sock\n',
        'milter-map.review': '# Only these Microsoft SMTP peers acquire the additional fail-closed Milter.\n'+''.join(n+' '+milters+', '+optional+'\n' for n in networks),
        'milter-header-checks.review': '/^X-RailTime-Local-Admission: e3p1; ticket=[0-9a-f]{64}$/ FILTER railtime-filter:admitted\n',
    }
    required = {
        'milter_connect_macros': ['j', '{client_addr}'],
        'milter_helo_macros': ['{tls_version}', '{cipher}', '{cipher_bits}'],
        'milter_mail_macros': ['{tls_version}', '{cipher}', '{cipher_bits}', '{mail_addr}'],
        'milter_end_of_data_macros': ['i'],
    }
    for setting, additions in required.items():
        if setting not in baseline or not isinstance(baseline[setting], str):
            raise ValueError('E3_FRESH_MACRO_BASELINE_REQUIRED')
        tokens = [s for s in re.split(r'[,\s]+', baseline[setting]) if s]
        if any(not re.fullmatch(r'(?:_|[A-Za-z][A-Za-z0-9_]*|\{[A-Za-z0-9_]+\})', token) for token in tokens):
            raise ValueError('E3_MACRO_BASELINE_INVALID')
        tokens += [s for s in additions if s not in tokens]
        output['main.cf.merge.review'] += setting+' = '+', '.join(tokens)+'\n'
    return output


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True, type=Path)
    parser.add_argument('--baseline', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path, help='New local review directory only')
    args = parser.parse_args()
    try:
        import time
        output = render(json.loads(args.config.read_text(encoding='utf-8-sig')), json.loads(args.baseline.read_text(encoding='utf-8-sig')), time.time())
        args.output.mkdir(parents=False, exist_ok=False)
        for name, text in output.items():
            with (args.output/name).open('x', encoding='utf-8', newline='\n') as stream:
                stream.write(text)
        print('{"schema":1,"state":"review-only","activationPerformed":false}')
    except Exception:
        raise SystemExit('E3_REVIEW_INPUT_NOT_ADMITTED')
