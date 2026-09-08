/*
 * PAdES profile check — CI-only tooling for BWH eSign.
 *
 * Asks European Commission DSS what PAdES baseline level a PDF's signature actually reaches,
 * and what DSS concludes about it, and prints the answer as JSON on stdout.
 *
 * Why this exists: pyHanko's own documentation states that its ordinary validation is NOT a
 * complete structural PAdES-profile conformance check. `scripts/validate-seal.sh` therefore
 * establishes that the seal is cryptographically sound and trusted, and nothing more. DSS
 * derives the *format* of a signature (PAdES-BASELINE-B / -T / -LT / -LTA, or one of the
 * non-baseline values such as PKCS7_B or PDF_NOT_ETSI) from its structure, which is the
 * profile-level statement that was missing. See docs/stage0/pades-profile.md.
 *
 * This class is never built into the production image and never shipped in the release
 * bundle; the repository's runtime is PHP only (AGENTS.md).
 *
 * Usage:
 *   PadesProfileCheck [--trust <pem>]... [--policy <xml>] [--report-dir <dir>]
 *                     [--json <path>] <pdf>...
 *
 * --policy takes an ETSI validation policy in DSS's own constraint format. Without it DSS's
 * stock policy is used, which is not what CI runs: see tools/dss/validation-policy.xml.
 *
 * Output. stdout carries a tab-separated summary, one line per input file:
 *
 *     <file>\t<level>\t<conclusion>\t<timestamps>
 *
 * so the driving shell script can compare it with a manifest without a JSON parser. The full
 * detail — every error, warning and info message DSS raised, the PDF revision facts, and each
 * timestamp's own conclusion — goes to the --json file, and DSS's own XML simple, detailed and
 * diagnostic reports go to --report-dir.
 *
 * Exit status is 0 whenever every file was *processed*; whether the results are the required
 * ones is scripts/validate-pades-profile.sh's judgement, not this program's. A file DSS could
 * not open at all is reported as "unreadable", which is a result too (that is what the
 * truncated fixture is for) and not a crash.
 */

import eu.europa.esig.dss.alert.SilentOnStatusAlert;
import eu.europa.esig.dss.diagnostic.DiagnosticData;
import eu.europa.esig.dss.diagnostic.PDFRevisionWrapper;
import eu.europa.esig.dss.diagnostic.SignatureWrapper;
import eu.europa.esig.dss.diagnostic.TimestampWrapper;
import eu.europa.esig.dss.enumerations.Indication;
import eu.europa.esig.dss.enumerations.SignatureLevel;
import eu.europa.esig.dss.enumerations.SubIndication;
import eu.europa.esig.dss.enumerations.TokenExtractionStrategy;
import eu.europa.esig.dss.jaxb.object.Message;
import eu.europa.esig.dss.model.DSSDocument;
import eu.europa.esig.dss.model.FileDocument;
import eu.europa.esig.dss.simplereport.SimpleReport;
import eu.europa.esig.dss.spi.DSSUtils;
import eu.europa.esig.dss.spi.validation.CommonCertificateVerifier;
import eu.europa.esig.dss.spi.x509.CommonTrustedCertificateSource;
import eu.europa.esig.dss.model.x509.CertificateToken;
import eu.europa.esig.dss.model.policy.ValidationPolicy;
import eu.europa.esig.dss.validation.SignedDocumentValidator;
import eu.europa.esig.dss.validation.policy.ValidationPolicyLoader;
import eu.europa.esig.dss.validation.reports.Reports;

import java.io.File;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.List;

public final class PadesProfileCheck {

    private PadesProfileCheck() {
    }

    public static void main(String[] args) throws Exception {
        List<String> trustPems = new ArrayList<>();
        List<String> inputs = new ArrayList<>();
        Path reportDir = null;
        Path jsonPath = null;
        String policyPath = null;

        for (int i = 0; i < args.length; i++) {
            switch (args[i]) {
                case "--trust" -> trustPems.add(requireValue(args, ++i, "--trust"));
                case "--report-dir" -> reportDir = Path.of(requireValue(args, ++i, "--report-dir"));
                case "--policy" -> policyPath = requireValue(args, ++i, "--policy");
                case "--json" -> jsonPath = Path.of(requireValue(args, ++i, "--json"));
                default -> {
                    if (args[i].startsWith("--")) {
                        throw new IllegalArgumentException("Unknown option: " + args[i]);
                    }
                    inputs.add(args[i]);
                }
            }
        }

        if (inputs.isEmpty()) {
            System.err.println("usage: PadesProfileCheck [--trust <pem>]... [--policy <xml>] "
                    + "[--report-dir <dir>] [--json <path>] <pdf>...");
            System.exit(2);
        }

        if (reportDir != null) {
            Files.createDirectories(reportDir);
        }

        // A named policy file is loaded eagerly, so a typo in the path fails the whole run
        // rather than silently degrading every artifact to DSS's stock policy.
        ValidationPolicy policy = policyPath == null
                ? null
                : ValidationPolicyLoader.fromValidationPolicy(new File(policyPath)).create();

        // Only the certificates handed in are trusted. Nothing else is: no EU trusted list is
        // fetched, no OS store is consulted, no online CRL or OCSP source is configured and no
        // AIA issuer fetch is allowed, so the run is fully offline and reproducible. That is deliberate — the fixture chain is
        // self-issued and publishes neither a responder nor a distribution point, and an
        // implicit network fetch would make the result depend on the runner's egress.
        CommonTrustedCertificateSource trusted = new CommonTrustedCertificateSource();
        List<String> trustSubjects = new ArrayList<>();
        for (String pem : trustPems) {
            CertificateToken token = DSSUtils.loadCertificate(new File(pem));
            trusted.addCertificate(token);
            trustSubjects.add(token.getSubject().getRFC2253());
        }

        StringBuilder out = new StringBuilder();
        out.append("{\n");
        out.append("  \"tool\": \"eu.europa.esig.dss (European Commission DSS)\",\n");
        out.append("  \"dssVersion\": ").append(json(dssVersion())).append(",\n");
        out.append("  \"java\": ").append(json(System.getProperty("java.version"))).append(",\n");
        out.append("  \"trustAnchors\": ").append(jsonStrings(trustSubjects)).append(",\n");
        out.append("  \"policy\": ").append(json(policyPath == null ? "DSS stock policy" : policyPath)).append(",\n");
        out.append("  \"results\": [\n");

        StringBuilder summary = new StringBuilder();
        for (int i = 0; i < inputs.size(); i++) {
            Result result = check(inputs.get(i), trusted, policy, reportDir);
            out.append(result.json());
            out.append(i == inputs.size() - 1 ? "\n" : ",\n");
            summary.append(result.summary()).append('\n');
        }

        out.append("  ]\n}\n");
        if (jsonPath != null) {
            if (jsonPath.getParent() != null) {
                Files.createDirectories(jsonPath.getParent());
            }
            Files.writeString(jsonPath, out.toString(), StandardCharsets.UTF_8);
        }
        System.out.print(summary);
    }

    /** One artifact's two renderings: the JSON fragment, and the tab-separated summary line. */
    private record Result(String json, String summary) {
    }

    private static String requireValue(String[] args, int index, String option) {
        if (index >= args.length) {
            throw new IllegalArgumentException(option + " needs a value");
        }
        return args[index];
    }

    private static Result check(String path, CommonTrustedCertificateSource trusted,
            ValidationPolicy policy, Path reportDir) {
        StringBuilder b = new StringBuilder();
        String name = new File(path).getName();
        b.append("    {\n      \"file\": ").append(json(name)).append(",\n");

        Reports reports;
        try {
            DSSDocument document = new FileDocument(path);
            SignedDocumentValidator validator = SignedDocumentValidator.fromDocument(document);

            CommonCertificateVerifier verifier = new CommonCertificateVerifier();
            verifier.setTrustedCertSources(trusted);
            // No revocation source is configured at all, so there is nothing to check against
            // and nothing to fetch. Silence the alerts so a missing responder is reported in
            // the validation result rather than thrown as an exception; the result is what we
            // want to record, including when it is unfavourable.
            verifier.setCheckRevocationForUntrustedChains(false);
            // No AIA source. DSS's default will otherwise dereference a certificate's
            // Authority Information Access URL to fetch a missing issuer, which it did for the
            // DigiCert timestamp chain before this line existed. That is a network fetch in
            // the middle of a validation, so the verdict would silently depend on the runner's
            // egress and on a third party's uptime. Every certificate this check reasons about
            // must come from the artifact itself or from --trust.
            verifier.setAIASource(null);
            verifier.setAlertOnMissingRevocationData(new SilentOnStatusAlert());
            verifier.setAlertOnRevokedCertificate(new SilentOnStatusAlert());
            verifier.setAlertOnNoRevocationAfterBestSignatureTime(new SilentOnStatusAlert());
            verifier.setAlertOnInvalidSignature(new SilentOnStatusAlert());
            verifier.setAlertOnInvalidTimestamp(new SilentOnStatusAlert());
            verifier.setAlertOnExpiredCertificate(new SilentOnStatusAlert());
            verifier.setAlertOnNotYetValidCertificate(new SilentOnStatusAlert());
            verifier.setAlertOnUncoveredPOE(new SilentOnStatusAlert());

            validator.setCertificateVerifier(verifier);
            validator.setTokenExtractionStrategy(TokenExtractionStrategy.NONE);

            reports = policy == null ? validator.validateDocument() : validator.validateDocument(policy);
        } catch (Exception e) {
            // A file DSS refuses to open is a result, not a failure of this program. The
            // truncated fixture is expected to land here.
            b.append("      \"opened\": false,\n");
            b.append("      \"error\": ").append(json(e.getClass().getName() + ": " + e.getMessage())).append("\n");
            b.append("    }");
            return new Result(b.toString(), summaryLine(name, "-", "unreadable", "-"));
        }

        SimpleReport simple = reports.getSimpleReport();
        DiagnosticData diagnostic = reports.getDiagnosticData();

        if (reportDir != null) {
            writeReport(reportDir.resolve(name + ".simple-report.xml"), reports.getXmlSimpleReport());
            writeReport(reportDir.resolve(name + ".detailed-report.xml"), reports.getXmlDetailedReport());
            writeReport(reportDir.resolve(name + ".diagnostic-data.xml"), reports.getXmlDiagnosticData());
        }

        b.append("      \"opened\": true,\n");
        b.append("      \"signatureCount\": ").append(simple.getSignaturesCount()).append(",\n");
        b.append("      \"validSignatureCount\": ").append(simple.getValidSignaturesCount()).append(",\n");
        b.append("      \"signatures\": [\n");

        List<String> ids = simple.getSignatureIdList();
        for (int i = 0; i < ids.size(); i++) {
            String id = ids.get(i);
            SignatureLevel level = simple.getSignatureFormat(id);
            Indication indication = simple.getIndication(id);
            SubIndication subIndication = simple.getSubIndication(id);
            SignatureWrapper wrapper = diagnostic.getSignatureById(id);

            b.append("        {\n");
            b.append("          \"id\": ").append(json(id)).append(",\n");
            // getSignatureFormat() is DSS's structural verdict on which ETSI profile the
            // signature satisfies. It is what closes the profile-conformance question: a
            // signature that is merely a valid PKCS#7 and not a conformant baseline signature
            // is reported as PKCS7_B / PDF_NOT_ETSI here, not as PAdES_BASELINE_B.
            b.append("          \"level\": ").append(json(level == null ? null : level.name())).append(",\n");
            b.append("          \"indication\": ").append(json(indication == null ? null : indication.name())).append(",\n");
            b.append("          \"subIndication\": ").append(json(subIndication == null ? null : subIndication.name())).append(",\n");
            b.append("          \"valid\": ").append(simple.isValid(id)).append(",\n");
            b.append("          \"signedBy\": ").append(json(simple.getSignedBy(id))).append(",\n");
            b.append("          \"digestAlgorithm\": ")
                    .append(json(wrapper == null || wrapper.getDigestAlgorithm() == null ? null : wrapper.getDigestAlgorithm().name())).append(",\n");
            b.append("          \"encryptionAlgorithm\": ")
                    .append(json(wrapper == null || wrapper.getEncryptionAlgorithm() == null ? null : wrapper.getEncryptionAlgorithm().name())).append(",\n");
            b.append("          \"signatureIntact\": ").append(wrapper != null && wrapper.isSignatureIntact()).append(",\n");
            b.append("          \"signatureValid\": ").append(wrapper != null && wrapper.isSignatureValid()).append(",\n");
            // The PDF-revision facts. These are the structural PAdES properties DSS reads out
            // of the signature dictionary itself, and the reason a profile checker is worth
            // more here than a second cryptographic checker: /SubFilter, the /ByteRange and
            // whether it is well formed, whether the dictionary is internally consistent, and
            // what DSS's own object-level diff says changed after the signed revision.
            b.append("          \"pdfRevision\": ").append(pdfRevision(wrapper)).append(",\n");
            b.append("          \"timestamps\": ").append(timestamps(wrapper, simple)).append(",\n");
            // Every message DSS raises is reported, including on a signature it accepts.
            // Warnings and info are not hidden: docs/stage0/pades-profile.md lists them.
            b.append("          \"errors\": ").append(messages(simple.getAdESValidationErrors(id))).append(",\n");
            b.append("          \"warnings\": ").append(messages(simple.getAdESValidationWarnings(id))).append(",\n");
            b.append("          \"info\": ").append(messages(simple.getAdESValidationInfo(id))).append("\n");
            b.append("        }").append(i == ids.size() - 1 ? "\n" : ",\n");
        }

        b.append("      ]\n    }");

        // The summary is deliberately refused rather than guessed when the shape is not the
        // one artifact/one signature this repository produces: a second signature would make
        // "the level" ambiguous, and an ambiguous line must fail the manifest match, not
        // quietly report the first signature.
        String summary;
        if (ids.isEmpty()) {
            summary = summaryLine(name, "-", "no-signature", "-");
        } else if (ids.size() > 1) {
            summary = summaryLine(name, "-", "multiple-signatures", "-");
        } else {
            String id = ids.get(0);
            SignatureLevel level = simple.getSignatureFormat(id);
            summary = summaryLine(name,
                    level == null ? "-" : level.name(),
                    conclusion(simple.getIndication(id), simple.getSubIndication(id)),
                    timestampSummary(diagnostic.getSignatureById(id), simple));
        }

        return new Result(b.toString(), summary);
    }

    private static String summaryLine(String name, String level, String conclusion, String timestamps) {
        return name + "\t" + level + "\t" + conclusion + "\t" + timestamps;
    }

    private static String conclusion(Indication indication, SubIndication subIndication) {
        if (indication == null) {
            return "-";
        }
        return subIndication == null ? indication.name() : indication.name() + "/" + subIndication.name();
    }

    private static String timestampSummary(SignatureWrapper wrapper, SimpleReport simple) {
        List<TimestampWrapper> tokens = wrapper == null ? null : wrapper.getTimestampList();
        if (tokens == null || tokens.isEmpty()) {
            return "none";
        }
        StringBuilder b = new StringBuilder();
        for (int i = 0; i < tokens.size(); i++) {
            TimestampWrapper t = tokens.get(i);
            b.append(conclusion(simple.getIndication(t.getId()), simple.getSubIndication(t.getId())));
            if (i != tokens.size() - 1) {
                b.append(',');
            }
        }
        return tokens.size() + ":" + b;
    }

    private static String pdfRevision(SignatureWrapper wrapper) {
        PDFRevisionWrapper revision = wrapper == null ? null : wrapper.getPDFRevision();
        if (revision == null) {
            return "null";
        }
        StringBuilder b = new StringBuilder("{");
        b.append("\"filter\": ").append(json(revision.getFilter()));
        b.append(", \"subFilter\": ").append(json(revision.getSubFilter()));
        b.append(", \"byteRange\": [");
        List<java.math.BigInteger> range = revision.getSignatureByteRange();
        if (range != null) {
            for (int i = 0; i < range.size(); i++) {
                b.append(range.get(i));
                if (i != range.size() - 1) {
                    b.append(", ");
                }
            }
        }
        b.append("]");
        b.append(", \"byteRangeValid\": ").append(revision.isSignatureByteRangeValid());
        b.append(", \"signatureDictionaryConsistent\": ").append(revision.isPdfSignatureDictionaryConsistent());
        b.append(", \"pdfModificationsDetected\": ").append(revision.arePdfModificationsDetected());
        b.append(", \"pdfObjectModificationsDetected\": ").append(revision.arePdfObjectModificationsDetected());
        b.append(", \"undefinedObjectChanges\": ")
                .append(revision.getPdfUndefinedChanges() == null ? 0 : revision.getPdfUndefinedChanges().size());
        b.append(", \"signatureFieldNames\": ").append(jsonStrings(
                revision.getSignatureFieldNames() == null ? List.of() : revision.getSignatureFieldNames()));
        b.append("}");
        return b.toString();
    }

    private static String timestamps(SignatureWrapper wrapper, SimpleReport simple) {
        if (wrapper == null) {
            return "[]";
        }
        List<TimestampWrapper> tokens = wrapper.getTimestampList();
        if (tokens == null || tokens.isEmpty()) {
            return "[]";
        }
        StringBuilder b = new StringBuilder("[");
        for (int i = 0; i < tokens.size(); i++) {
            TimestampWrapper t = tokens.get(i);
            b.append("{\"type\": ").append(json(t.getType() == null ? null : t.getType().name()));
            b.append(", \"productionTime\": ").append(json(t.getProductionTime() == null ? null : t.getProductionTime().toInstant().toString()));
            b.append(", \"messageImprintIntact\": ").append(t.isMessageImprintDataIntact());
            b.append(", \"signatureIntact\": ").append(t.isSignatureIntact());
            b.append(", \"signatureValid\": ").append(t.isSignatureValid());
            // The timestamp token gets its own conclusion, separate from the signature's.
            // Reported because DSS can call a signature TOTAL_PASSED at BASELINE_T while the
            // timestamp's own chain is untrusted, and that must not be silently absorbed.
            Indication tsIndication = simple.getIndication(t.getId());
            SubIndication tsSubIndication = simple.getSubIndication(t.getId());
            b.append(", \"indication\": ").append(json(tsIndication == null ? null : tsIndication.name()));
            b.append(", \"subIndication\": ").append(json(tsSubIndication == null ? null : tsSubIndication.name()));
            b.append(", \"producedBy\": ").append(json(simple.getProducedBy(t.getId())));
            b.append("}").append(i == tokens.size() - 1 ? "" : ", ");
        }
        return b.append("]").toString();
    }

    private static String messages(List<Message> messages) {
        List<String> values = new ArrayList<>();
        if (messages != null) {
            for (Message message : messages) {
                values.add(message.getValue());
            }
        }
        return jsonStrings(values);
    }

    private static void writeReport(Path path, String xml) {
        try {
            Files.writeString(path, xml == null ? "" : xml, StandardCharsets.UTF_8);
        } catch (Exception e) {
            throw new IllegalStateException("Could not write " + path, e);
        }
    }

    private static String dssVersion() {
        // DSS's jars carry no Implementation-Version in their manifest, so the version is read
        // off the jar the classes were actually loaded from. That is the honest answer: it
        // names the artifact in use rather than repeating a number from the pom.
        try {
            java.security.CodeSource source = SignedDocumentValidator.class.getProtectionDomain().getCodeSource();
            if (source == null || source.getLocation() == null) {
                return "unknown (no code source)";
            }
            String jar = new File(source.getLocation().toURI()).getName();
            java.util.regex.Matcher m = java.util.regex.Pattern
                    .compile("^dss-validation-(.+)\\.jar$").matcher(jar);
            return m.matches() ? m.group(1) : jar;
        } catch (Exception e) {
            return "unknown (" + e.getClass().getSimpleName() + ")";
        }
    }

    private static String jsonStrings(List<String> values) {
        StringBuilder b = new StringBuilder("[");
        for (int i = 0; i < values.size(); i++) {
            b.append(json(values.get(i)));
            if (i != values.size() - 1) {
                b.append(", ");
            }
        }
        return b.append("]").toString();
    }

    private static String json(String value) {
        if (value == null) {
            return "null";
        }
        StringBuilder b = new StringBuilder("\"");
        for (int i = 0; i < value.length(); i++) {
            char c = value.charAt(i);
            switch (c) {
                case '"' -> b.append("\\\"");
                case '\\' -> b.append("\\\\");
                case '\n' -> b.append("\\n");
                case '\r' -> b.append("\\r");
                case '\t' -> b.append("\\t");
                default -> {
                    if (c < 0x20) {
                        b.append(String.format("\\u%04x", (int) c));
                    } else {
                        b.append(c);
                    }
                }
            }
        }
        return b.append("\"").toString();
    }
}
