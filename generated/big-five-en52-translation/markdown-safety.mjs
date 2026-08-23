const FORBIDDEN_HTML_COMMENT_SYNTAX = /<!--|--!?>/;

export function hasForbiddenHtmlCommentSyntax(value) {
  return FORBIDDEN_HTML_COMMENT_SYNTAX.test(String(value));
}
