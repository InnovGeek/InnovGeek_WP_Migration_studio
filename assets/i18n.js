/* InnovGeek Backup Studio — display-only EN localization.
 * The original French interface, AJAX workflows, backup/restore algorithms,
 * archive format, and identifiers are deliberately unchanged.
 * WordPress user locale determines whether this adapter runs.
 */
(() => {
  'use strict';
  const config = window.IGBK_L10N;
  if (!config || config.locale !== 'en' || !config.strings) return;
  const root = document.querySelector('.igbk-root');
  if (!root) return;

  // Longest phrases first: translate complete UI sentences before their shorter parts.
  const entries = Object.entries(config.strings)
    .filter(([source, target]) => source && target && source !== target)
    .sort((a, b) => b[0].length - a[0].length);
  const units = { 'Ko': 'KB', 'Mo': 'MB', 'Go': 'GB', 'To': 'TB', 'o': 'B' };

  function translate(text) {
    if (!text || !text.trim()) return text;
    let result = text;
    for (const [source, target] of entries) {
      if (result.includes(source)) result = result.split(source).join(target);
    }
    result = result.replace(/(\d(?:[.,]\d+)?\s*)(Ko|Mo|Go|To|o)(?![\p{L}\p{N}_])/gu,
      (_, number, unit) => number + units[unit]);
    return result;
  }

  function translateText(node) {
    const parent = node.parentElement;
    if (parent && parent.closest('code,pre,script,style,textarea')) return;
    const before = node.nodeValue;
    const after = translate(before);
    if (after !== before) node.nodeValue = after;
  }

  function translateAttributes(element) {
    for (const name of ['alt', 'aria-label', 'title', 'placeholder']) {
      if (!element.hasAttribute(name)) continue;
      const before = element.getAttribute(name);
      const after = translate(before);
      if (after !== before) element.setAttribute(name, after);
    }
  }

  function translateTree(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      translateText(node);
      return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return;
    const element = node;
    if (element.matches('code,pre,script,style,textarea')) return;
    translateAttributes(element);
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
    let current;
    while ((current = walker.nextNode())) {
      if (current.nodeType === Node.TEXT_NODE) translateText(current);
      else if (!current.closest('code,pre,script,style,textarea')) translateAttributes(current);
    }
  }

  translateTree(root);

  // Existing app text is updated through textContent at runtime; translate only
  // changed nodes, with no changes to AJAX/backup/restore code or timings.
  const observer = new MutationObserver(records => {
    for (const record of records) {
      if (record.type === 'characterData') translateText(record.target);
      else if (record.type === 'attributes') translateAttributes(record.target);
      else for (const child of record.addedNodes) translateTree(child);
    }
  });
  observer.observe(root, {
    subtree: true,
    childList: true,
    characterData: true,
    attributes: true,
    attributeFilter: ['alt', 'aria-label', 'title', 'placeholder']
  });
})();
