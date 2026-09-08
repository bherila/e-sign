import '@testing-library/jest-dom';

/**
 * jsdom does not implement `PointerEvent` (jsdom/jsdom#2527), and Base UI's controls branch on
 * `instanceof PointerEvent` to tell a real pointer press from a synthetic click. Without the
 * constructor those branches throw a `ReferenceError` and a click on a `ui/checkbox` never
 * reaches its handler — an environment gap that would otherwise look like a broken control.
 *
 * A subclass of `MouseEvent` carrying the pointer properties is the standard shim: every
 * pointer event *is* a mouse event with extra fields, and nothing in this codebase reads more
 * of them than `pointerId` and `pointerType`.
 */
if (typeof window !== 'undefined' && typeof window.PointerEvent === 'undefined') {
  class PointerEventShim extends MouseEvent implements PointerEvent {
    public readonly pointerId: number;
    public readonly width: number;
    public readonly height: number;
    public readonly pressure: number;
    public readonly tangentialPressure: number;
    public readonly tiltX: number;
    public readonly tiltY: number;
    public readonly twist: number;
    public readonly altitudeAngle: number;
    public readonly azimuthAngle: number;
    public readonly pointerType: string;
    public readonly isPrimary: boolean;

    constructor(type: string, params: PointerEventInit = {}) {
      super(type, params);
      this.pointerId = params.pointerId ?? 0;
      this.width = params.width ?? 1;
      this.height = params.height ?? 1;
      this.pressure = params.pressure ?? 0;
      this.tangentialPressure = params.tangentialPressure ?? 0;
      this.tiltX = params.tiltX ?? 0;
      this.tiltY = params.tiltY ?? 0;
      this.twist = params.twist ?? 0;
      this.altitudeAngle = params.altitudeAngle ?? 0;
      this.azimuthAngle = params.azimuthAngle ?? 0;
      this.pointerType = params.pointerType ?? 'mouse';
      this.isPrimary = params.isPrimary ?? false;
    }

    getCoalescedEvents(): PointerEvent[] {
      return [];
    }

    getPredictedEvents(): PointerEvent[] {
      return [];
    }
  }

  window.PointerEvent = PointerEventShim as unknown as typeof PointerEvent;
  globalThis.PointerEvent = window.PointerEvent;
}

/**
 * jsdom has no layout engine, so `Element.scrollIntoView` is absent. Page navigation calls it;
 * a missing method there is not a failure worth a test-only branch in production code.
 */
if (typeof Element !== 'undefined' && typeof Element.prototype.scrollIntoView !== 'function') {
  Element.prototype.scrollIntoView = function scrollIntoView(): void {
    /* no layout to scroll */
  };
}
