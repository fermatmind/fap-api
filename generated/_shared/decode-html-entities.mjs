export function decodeHtmlEntitiesOnce(value) {
  return String(value)
    .replace(/&quot;|&#34;/g, '"')
    .replace(/&#x27;|&#39;|&apos;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&amp;/g, '&');
}
