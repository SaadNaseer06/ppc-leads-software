/**
 * Splits an email into what the person wrote and the earlier emails their mail app quoted under it
 * ("On Tue, Nov 10 … wrote:" followed by "> …" lines, or Outlook's "-----Original Message-----").
 */
export function splitQuoted(body: string): { text: string; quoted: string } {
  const lines = body.replace(/\r\n/g, "\n").split("\n");
  let cut = -1;

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i].trim();
    if (/^-{2,}\s*original message\s*-{2,}$/i.test(line)) {
      cut = i;
      break;
    }
    // Outlook: a "From:" line with "Sent:" or "Date:" just below
    if (/^from:\s/i.test(line) && lines.slice(i + 1, i + 4).some((l) => /^(sent|date):\s/i.test(l.trim()))) {
      cut = i;
      break;
    }
    if (line.startsWith(">")) {
      cut = i;
      // The "On … wrote:" line above it, which Gmail often wraps over two or three lines
      for (let j = i - 1; j >= Math.max(0, i - 4); j--) {
        const above = lines[j].trim();
        if (above === "" && j === i - 1) continue;
        if (/^on\s/i.test(above)) {
          cut = j;
          break;
        }
        if (above === "") break;
      }
      break;
    }
    if (/^on\s.*wrote:$/i.test(line)) {
      cut = i;
      break;
    }
  }

  if (cut <= 0) return cut === 0 ? { text: "", quoted: body.trim() } : { text: body.trim(), quoted: "" };
  return { text: lines.slice(0, cut).join("\n").trim(), quoted: lines.slice(cut).join("\n").trim() };
}

/** One line of an email for a list: what was written, without the quoted history */
export function emailSnippet(body: string, max = 140): string {
  const { text, quoted } = splitQuoted(body);
  const flat = (text || quoted).replace(/\s+/g, " ").trim();
  return flat.length > max ? `${flat.slice(0, max - 1)}…` : flat;
}

export function replySubject(subject: string): string {
  return /^re:/i.test(subject.trim()) ? subject.trim() : `Re: ${subject.trim()}`;
}
