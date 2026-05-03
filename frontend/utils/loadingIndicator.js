export function formatLoadingIndicatorMessage(message) {
    return `${String(message ?? '').replace(/(?:\s*(?:\.{3,}|…))+$/u, '').trimEnd()}…`;
}
