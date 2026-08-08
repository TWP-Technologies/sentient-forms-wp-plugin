const collectTemplateRanges = (ast) => {
  const ranges = [];
  const visit = (value, tagged = false) => {
    if (Array.isArray(value)) {
      value.forEach((item) => visit(item, tagged));
      return;
    }
    if (!value || typeof value !== 'object') {
      return;
    }
    if (value.type === 'TaggedTemplateExpression') {
      visit(value.tag, false);
      visit(value.quasi, true);
      return;
    }
    if (value.type === 'TemplateLiteral') {
      value.quasis.forEach((quasi) => {
        ranges.push({ start: quasi.start, end: quasi.end, tagged });
      });
      value.expressions.forEach((expression) => visit(expression, false));
      return;
    }
    for (const [key, child] of Object.entries(value)) {
      if (!['start', 'end', 'loc', 'range'].includes(key)) {
        visit(child, tagged);
      }
    }
  };
  visit(ast);
  return ranges;
};

export const canonicalizeGeneratedWhitespace = (code, ast, fileName) => {
  const templateRanges = collectTemplateRanges(ast);
  const trailing = /[\t ]+(?=\r?\n|$)/g;
  let output = '';
  let cursor = 0;
  for (const match of code.matchAll(trailing)) {
    const start = match.index;
    const range = templateRanges.find(({ start: rangeStart, end }) => start >= rangeStart && start < end);
    output += code.slice(cursor, start);
    if (range?.tagged) {
      throw new Error(`Tagged template contains non-canonical line-end whitespace: ${fileName}`);
    }
    if (range) {
      let backslashCount = 0;
      for (let index = start - 1; index >= 0 && code[index] === '\\'; index -= 1) {
        backslashCount += 1;
      }
      if (backslashCount % 2 === 1) {
        throw new Error(`Template escape precedes non-canonical line-end whitespace: ${fileName}`);
      }
      output += match[0].replaceAll(' ', '\\x20').replaceAll('\t', '\\t');
    }
    cursor = start + match[0].length;
  }
  return (output + code.slice(cursor)).replaceAll('\r\n', '\n').replaceAll('\r', '\n');
};

export const createGeneratedWhitespacePlugin = () => ({
  name: 'sentient-forms-generated-whitespace',
  enforce: 'post',
  renderChunk: {
    order: 'post',
    handler(code, chunk) {
      const canonical = canonicalizeGeneratedWhitespace(code, this.parse(code), chunk.fileName);
      return canonical === code ? null : { code: canonical, map: null };
    }
  }
});
