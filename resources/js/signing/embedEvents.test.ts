import { announcementTarget, type EmbedConfig, postSigningEvent, readEmbedConfig } from "./embedEvents";

/**
 * The embedding contract's emitter (issue #168): exact origins only, nothing without an
 * allowlist, and no personal data in the message.
 */
describe("embedEvents", () => {
  const fixedNow = () => new Date("2026-11-08T00:00:00.000Z");

  function recordingTarget(): { target: Window; calls: Array<[unknown, string]> } {
    const calls: Array<[unknown, string]> = [];
    const target = {
      postMessage: (message: unknown, origin: string) => {
        calls.push([message, origin]);
      },
    } as unknown as Window;

    return { target, calls };
  }

  afterEach(() => {
    delete document.body.dataset.signingEvents;
  });

  it("posts once per allowed origin, with that origin as the target, never '*'", () => {
    const { target, calls } = recordingTarget();
    const config: EmbedConfig = {
      origins: ["https://app.example.test", "https://admin.example.test"],
      event: null,
    };

    const posted = postSigningEvent(
      { type: "signing.loaded", envelope_id: "01JENV", recipient_id: "01JREC", total_fields: 3 },
      config,
      target,
      fixedNow,
    );

    expect(posted).toBe(2);
    expect(calls.map(([, origin]) => origin)).toEqual(["https://app.example.test", "https://admin.example.test"]);
    expect(calls[0]?.[0]).toEqual({
      type: "signing.loaded",
      envelope_id: "01JENV",
      recipient_id: "01JREC",
      total_fields: 3,
      source: "esign-signing",
      version: 1,
      timestamp: "2026-11-08T00:00:00.000Z",
    });
  });

  it("posts nothing without an allowlist or without anyone to tell", () => {
    const { target, calls } = recordingTarget();

    expect(postSigningEvent({ type: "signing.started" }, null, target)).toBe(0);
    expect(postSigningEvent({ type: "signing.started" }, { origins: ["https://a.test"], event: null }, null)).toBe(0);
    expect(calls).toHaveLength(0);
  });

  it("reads the server's attribute and refuses a wildcard origin", () => {
    document.body.dataset.signingEvents = JSON.stringify({
      origins: ["*", "https://app.example.test"],
      event: { type: "signing.error", code: "link_unusable" },
    });

    expect(readEmbedConfig()).toEqual({
      origins: ["https://app.example.test"],
      event: { type: "signing.error", code: "link_unusable" },
    });
  });

  it("treats a missing, empty, or malformed attribute as no allowlist", () => {
    expect(readEmbedConfig()).toBeNull();

    document.body.dataset.signingEvents = JSON.stringify({ origins: ["*"] });
    expect(readEmbedConfig()).toBeNull();

    document.body.dataset.signingEvents = "{not json";
    expect(readEmbedConfig()).toBeNull();
  });

  it("announces to nobody from a top-level page with no opener", () => {
    expect(announcementTarget(window)).toBeNull();
  });
});
