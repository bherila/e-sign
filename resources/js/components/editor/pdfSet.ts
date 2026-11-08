import type { LoadedPdf } from "./pdfjs";

/**
 * Several loaded PDFs as one, with pages numbered across the set (issue #163).
 *
 * A document set's fields use absolute pages — the first document's pages are 1..n, the
 * second's start at n+1 (docs/preparation/document-sets.md) — so the editor and the signing page
 * want exactly the interface one document gives them, over all the documents in order. This is
 * that, and nothing more: it maps an absolute page to the document it belongs to and that
 * document's own page number, and destroys every member together.
 */
export function composePdfs(members: LoadedPdf[]): LoadedPdf {
  if (members.length === 0) {
    throw new Error("A document set has at least one document.");
  }

  if (members.length === 1) {
    return members[0]!;
  }

  const firstPages: number[] = [];
  let next = 1;

  for (const member of members) {
    firstPages.push(next);
    next += member.pageCount;
  }

  return {
    pageCount: next - 1,

    renderPage(pageNumber, canvas, cssWidth, cssHeight, devicePixelRatio) {
      for (let index = members.length - 1; index >= 0; index--) {
        if (pageNumber >= firstPages[index]!) {
          const local = pageNumber - firstPages[index]! + 1;

          if (local > members[index]!.pageCount) {
            break;
          }

          return members[index]!.renderPage(local, canvas, cssWidth, cssHeight, devicePixelRatio);
        }
      }

      return Promise.reject(new Error(`Page ${pageNumber} is not in this document set.`));
    },

    async destroy() {
      await Promise.all(members.map((member) => member.destroy()));
    },
  };
}

/** Load each URL in order and compose them. One URL is exactly `loadPdf`. */
export async function loadPdfSet(
  urls: string[],
  load: (url: string) => Promise<LoadedPdf>,
): Promise<LoadedPdf> {
  const members: LoadedPdf[] = [];

  try {
    for (const url of urls) {
      members.push(await load(url));
    }
  } catch (error) {
    await Promise.all(members.map((member) => member.destroy()));
    throw error;
  }

  return composePdfs(members);
}
