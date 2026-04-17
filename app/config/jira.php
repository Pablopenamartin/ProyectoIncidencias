<?php
function jira_site(): string { return rtrim(env('JIRA_SITE', ''), '/'); }
function jira_cloud_id(): string { return trim(env('JIRA_CLOUD_ID', '')); }
function jira_use_atlassian_api(): bool {
    return in_array(strtolower((string) env('JIRA_USE_ATLASSIAN_API','true')), ['1','true','yes','on'], true);
}
function jira_api_base(): string {
    if (jira_use_atlassian_api()) {
        $cid = jira_cloud_id();
        if ($cid === '') throw new RuntimeException('JIRA_CLOUD_ID no definido');
        return "https://api.atlassian.com/ex/jira/{$cid}/rest/api/3";
    }
    $site = jira_site();
    if ($site === '') throw new RuntimeException('JIRA_SITE no definido');
    return "{$site}/rest/api/3";
}
function jira_endpoint(string $path): string {
    return rtrim(jira_api_base(), '/') . '/' . ltrim($path, '/');
}
function jira_search_url(): string { return jira_endpoint('/search/jql'); }
function jira_headers(): array {
    $email = env('JIRA_EMAIL',''); $token = env('JIRA_API_TOKEN','');
    if ($email === '' || $token === '') throw new RuntimeException('JIRA_EMAIL/JIRA_API_TOKEN no definidos');
    return ['Authorization: Basic ' . base64_encode($email.':'.$token), 'Accept: application/json'];
}