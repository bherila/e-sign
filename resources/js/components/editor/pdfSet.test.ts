import type { LoadedPdf } from "./pdfjs";
import { composePdfs, loadPdfSet } from "./pdfSet";

function fake(name: string, pageCount: number, calls: string[]): LoadedPdf {
  return {
    pageCount,
    renderPage: (page) => {
      calls.push(`${name}:${page}`);
      return Promise.resolve();
    },
    destroy: () => {
      calls.push(`${name}:destroyed`);
      return Promise.resolve();
    },
  };
}

const canvas = {} as HTMLCanvasElement;

describe("a document set as one PDF", () => {
  it("numbers pages across the set and renders each from its own document", async () => {
    const calls: string[] = [];
    const set = composePdfs([fake("a", 3, calls), fake("b", 1, calls), fake("c", 2, calls)]);

    expect(set.pageCount).toBe(6);

    for (const page of [1, 3, 4, 5, 6]) {
      await set.renderPage(page, canvas, 100, 100, 1);
    }

    expect(calls).toEqual(["a:1", "a:3", "b:1", "c:1", "c:2"]);
  });

  it("refuses a page outside the set rather than drawing another document's page", async () => {
    const set = composePdfs([fake("a", 1, []), fake("b", 1, [])]);

    await expect(set.renderPage(3, canvas, 1, 1, 1)).rejects.toThrow("not in this document set");
    await expect(set.renderPage(0, canvas, 1, 1, 1)).rejects.toThrow("not in this document set");
  });

  it("is the document itself when the set has one", () => {
    const only = fake("a", 2, []);

    expect(composePdfs([only])).toBe(only);
  });

  it("destroys every member, and the ones already loaded when a later one fails", async () => {
    const calls: string[] = [];
    const loaded = fake("a", 1, calls);

    await expect(
      loadPdfSet(["/a", "/b"], (url) => (url === "/a" ? Promise.resolve(loaded) : Promise.reject(new Error("boom")))),
    ).rejects.toThrow("boom");

    expect(calls).toEqual(["a:destroyed"]);
  });
});
