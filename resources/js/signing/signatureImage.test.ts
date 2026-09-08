import {
  decodedByteLength,
  drawnSignatureDataUrl,
  drawStrokes,
  drawTypedName,
  exportPng,
  isBlank,
  MIN_STROKE_LENGTH,
  prepare,
  type SignatureCanvas,
  SignatureCaptureError,
  type SignatureContext,
  type Stroke,
  totalStrokeLength,
  typedSignatureDataUrl,
} from "./signatureImage";

/**
 * The canvas helpers, against a recording double.
 *
 * jsdom has no 2D context, so the module takes structural interfaces rather than
 * `HTMLCanvasElement` and this file supplies them. That is not a workaround bolted on for the
 * test: it is what lets the "is this actually a signature" rule be checked at all, and the
 * alternative was a compiled `canvas` dependency in a project that has to stay
 * `pnpm install` on a shared host.
 *
 * The rule under test is docs/HANDOFF.md section 8's: never record a signature merely because
 * a canvas is non-empty.
 */

interface Call {
  method: string;
  args: unknown[];
}

interface Recording {
  canvas: SignatureCanvas;
  context: SignatureContext;
  calls: Call[];
  /** What `toDataURL` will return. Overridable, to test a browser that misbehaves. */
  setDataUrl(value: string): void;
}

function recordingCanvas(measuredWidth = 100): Recording {
  const calls: Call[] = [];
  let dataUrl = "data:image/png;base64,AAAA";

  const record =
    (method: string) =>
    (...args: unknown[]): void => {
      calls.push({ method, args });
    };

  const context: SignatureContext = {
    lineWidth: 0,
    lineCap: "butt",
    lineJoin: "miter",
    strokeStyle: "",
    fillStyle: "",
    font: "",
    textBaseline: "alphabetic",
    textAlign: "start",
    clearRect: record("clearRect"),
    beginPath: record("beginPath"),
    moveTo: record("moveTo"),
    lineTo: record("lineTo"),
    stroke: record("stroke"),
    fillText: record("fillText"),
    measureText: (text: string) => {
      calls.push({ method: "measureText", args: [text] });

      return { width: measuredWidth };
    },
    setTransform: record("setTransform"),
  };

  const canvas: SignatureCanvas = {
    width: 0,
    height: 0,
    getContext: () => context,
    toDataURL: () => dataUrl,
  };

  return {
    canvas,
    context,
    calls,
    setDataUrl(value: string): void {
      dataUrl = value;
    },
  };
}

function stroke(length: number): Stroke {
  return [
    { x: 0, y: 0 },
    { x: length, y: 0 },
  ];
}

describe("totalStrokeLength", () => {
  it("sums the distances between consecutive points", () => {
    expect(totalStrokeLength([[{ x: 0, y: 0 }, { x: 3, y: 4 }]])).toBe(5);
  });

  it("sums across separate strokes", () => {
    expect(totalStrokeLength([stroke(10), stroke(15)])).toBe(25);
  });

  it("counts nothing for a single point, because a dot is not a path", () => {
    expect(totalStrokeLength([[{ x: 4, y: 4 }]])).toBe(0);
  });

  it("is zero for no strokes at all", () => {
    expect(totalStrokeLength([])).toBe(0);
  });
});

describe("isBlank", () => {
  it("rejects an empty canvas", () => {
    expect(isBlank([])).toBe(true);
  });

  it("rejects a single tap, which is what a pocket touch leaves", () => {
    expect(isBlank([[{ x: 10, y: 10 }]])).toBe(true);
  });

  it("rejects a scratch below the threshold even though the canvas is not empty", () => {
    // This is the case the rule exists for: strokes are present, so "is the canvas empty" is
    // false, and it is still not a signature.
    expect(isBlank([stroke(MIN_STROKE_LENGTH - 1)])).toBe(true);
  });

  it("accepts a deliberate mark", () => {
    expect(isBlank([stroke(MIN_STROKE_LENGTH + 1)])).toBe(false);
  });

  it("accepts several short strokes that add up, as initials do", () => {
    expect(isBlank([stroke(20), stroke(20), stroke(20)])).toBe(false);
  });
});

describe("prepare", () => {
  it("sizes the backing store for the device pixel ratio and clears it", () => {
    const recording = recordingCanvas();

    prepare(recording.canvas, { width: 320, height: 110, devicePixelRatio: 2 });

    expect(recording.canvas.width).toBe(640);
    expect(recording.canvas.height).toBe(220);
    expect(recording.calls).toContainEqual({ method: "setTransform", args: [2, 0, 0, 2, 0, 0] });
    expect(recording.calls).toContainEqual({ method: "clearRect", args: [0, 0, 320, 110] });
  });

  it("uses setTransform rather than scale, so repeated redraws do not compound", () => {
    const recording = recordingCanvas();
    const options = { width: 100, height: 50, devicePixelRatio: 3 };

    prepare(recording.canvas, options);
    prepare(recording.canvas, options);

    const transforms = recording.calls.filter((call) => call.method === "setTransform");
    expect(transforms).toHaveLength(2);
    expect(transforms.every((call) => call.args[0] === 3)).toBe(true);
    expect(recording.canvas.width).toBe(300);
  });

  it("treats a nonsense device pixel ratio as 1", () => {
    const recording = recordingCanvas();

    prepare(recording.canvas, { width: 100, height: 50, devicePixelRatio: 0 });

    expect(recording.canvas.width).toBe(100);
  });

  it("throws when the browser gives no drawing surface", () => {
    const recording = recordingCanvas();
    const canvas: SignatureCanvas = { ...recording.canvas, getContext: () => null };

    expect(() => prepare(canvas, { width: 10, height: 10, devicePixelRatio: 1 })).toThrow(
      SignatureCaptureError,
    );
  });
});

describe("drawStrokes", () => {
  it("opens a path per stroke and follows every point", () => {
    const recording = recordingCanvas();

    drawStrokes(recording.context, [
      [
        { x: 1, y: 1 },
        { x: 2, y: 2 },
        { x: 3, y: 3 },
      ],
      [
        { x: 9, y: 9 },
        { x: 8, y: 8 },
      ],
    ]);

    expect(recording.calls.filter((call) => call.method === "beginPath")).toHaveLength(2);
    expect(recording.calls.filter((call) => call.method === "stroke")).toHaveLength(2);
    expect(recording.calls.filter((call) => call.method === "lineTo")).toHaveLength(3);
  });

  it("draws a dot for a single-point stroke, so a deliberate full stop survives", () => {
    const recording = recordingCanvas();

    drawStrokes(recording.context, [[{ x: 5, y: 5 }]]);

    expect(recording.calls).toContainEqual({ method: "moveTo", args: [5, 5] });
    expect(recording.calls.filter((call) => call.method === "lineTo")).toHaveLength(1);
  });

  it("skips an empty stroke without drawing anything", () => {
    const recording = recordingCanvas();

    drawStrokes(recording.context, [[]]);

    expect(recording.calls.filter((call) => call.method === "beginPath")).toHaveLength(0);
  });
});

describe("drawTypedName", () => {
  const options = { width: 320, height: 110, devicePixelRatio: 1 };

  it("centres the trimmed name", () => {
    const recording = recordingCanvas(50);

    drawTypedName(recording.context, "  Avery Counterparty  ", options);

    expect(recording.calls).toContainEqual({
      method: "fillText",
      args: ["Avery Counterparty", 160, 55],
    });
    expect(recording.context.textAlign).toBe("center");
    expect(recording.context.textBaseline).toBe("middle");
  });

  it("shrinks the type until the name fits the box", () => {
    // A double that always reports the name as too wide forces the loop to its floor.
    const recording = recordingCanvas(10_000);

    drawTypedName(recording.context, "A Very Long Name Indeed", options);

    expect(recording.context.font).toContain("10px");
  });

  it("keeps the largest size when the name already fits", () => {
    const recording = recordingCanvas(10);

    drawTypedName(recording.context, "Ann", options);

    // 60% of the box height, which is the starting size.
    expect(recording.context.font).toContain("66px");
  });

  it("uses a system font stack rather than a remote script face", () => {
    // AGENTS.md forbids third-party origins on this page, and a cursive webfont would be
    // either that or a bundled asset pretending a keyboard produced handwriting.
    const recording = recordingCanvas(10);

    drawTypedName(recording.context, "Ann", options);

    expect(recording.context.font).toContain("Helvetica");
    expect(recording.context.font).not.toContain("http");
  });

  it("refuses an empty name", () => {
    const recording = recordingCanvas();

    expect(() => drawTypedName(recording.context, "   ", options)).toThrow(SignatureCaptureError);
  });
});

describe("exportPng", () => {
  it("returns the data URL the canvas produced", () => {
    const recording = recordingCanvas();
    recording.setDataUrl("data:image/png;base64,QUJD");

    expect(exportPng(recording.canvas)).toBe("data:image/png;base64,QUJD");
  });

  it("refuses anything that is not a PNG data URL", () => {
    const recording = recordingCanvas();
    recording.setDataUrl("data:image/jpeg;base64,QUJD");

    expect(() => exportPng(recording.canvas)).toThrow(SignatureCaptureError);
  });

  it("refuses an empty payload", () => {
    const recording = recordingCanvas();
    recording.setDataUrl("data:image/png;base64,");

    expect(() => exportPng(recording.canvas)).toThrow(SignatureCaptureError);
  });
});

describe("decodedByteLength", () => {
  it("measures the decoded size of a base64 payload", () => {
    // "ABC" is three bytes and encodes to "QUJD" with no padding.
    expect(decodedByteLength("data:image/png;base64,QUJD")).toBe(3);
  });

  it("accounts for padding", () => {
    expect(decodedByteLength("data:image/png;base64,QQ==")).toBe(1);
    expect(decodedByteLength("data:image/png;base64,QUI=")).toBe(2);
  });

  it("is zero for a string with no payload", () => {
    expect(decodedByteLength("not a data url")).toBe(0);
  });
});

describe("the two adoption paths", () => {
  const options = { width: 320, height: 110, devicePixelRatio: 1 };

  it("produces a PNG data URL from a drawing", () => {
    const recording = recordingCanvas();

    expect(drawnSignatureDataUrl(recording.canvas, [stroke(200)], options)).toMatch(
      /^data:image\/png;base64,/,
    );
  });

  it("refuses to adopt a drawing that is not a signature", () => {
    const recording = recordingCanvas();

    expect(() => drawnSignatureDataUrl(recording.canvas, [[{ x: 1, y: 1 }]], options)).toThrow(
      /not enough of a mark/,
    );
  });

  it("produces a PNG data URL from a typed name", () => {
    const recording = recordingCanvas(10);

    expect(typedSignatureDataUrl(recording.canvas, "Avery Counterparty", options)).toMatch(
      /^data:image\/png;base64,/,
    );
  });

  it("refuses to adopt an empty typed name", () => {
    const recording = recordingCanvas(10);

    expect(() => typedSignatureDataUrl(recording.canvas, "", options)).toThrow(
      SignatureCaptureError,
    );
  });
});
