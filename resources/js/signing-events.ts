import { postSigningEvent, readEmbedConfig } from '@/signing/embedEvents';

/**
 * Entry point for the embedding event contract (docs/signing/embedded-events.md).
 *
 * Loaded by the signing layout only when the deployment allowlists an origin. It announces the
 * event the page itself stands for — a completed or declined signature, or a refusal — once,
 * on load. The interactive signing page announces its own progress from inside the island.
 */
const config = readEmbedConfig();

if (config?.event) {
  postSigningEvent(config.event, config);
}
