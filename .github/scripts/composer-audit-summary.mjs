// Resume lisible de `composer audit --format=json` dans le resume du job.
// Echoue des qu'une vulnerabilite est presente, quelle que soit sa severite.
// Usage : node .github/scripts/composer-audit-summary.mjs composer-audit.json

import { readFileSync, appendFileSync } from "node:fs";

const reportPath = process.argv[2] ?? "composer-audit.json";
const report = JSON.parse(readFileSync(reportPath, "utf8"));
const advisories = report.advisories ?? {};
const abandoned = Object.entries(report.abandoned ?? {});
const counts = { critical: 0, high: 0, moderate: 0, low: 0, unknown: 0 };
const rows = [];

for (const [packageName, packageAdvisories] of Object.entries(advisories)) {
  for (const advisory of packageAdvisories ?? []) {
    const severity = String(advisory.severity ?? "unknown").toLowerCase();
    const bucket = severity in counts ? severity : "unknown";
    counts[bucket]++;
    rows.push({
      packageName,
      severity: bucket,
      title: advisory.title ?? advisory.advisoryId ?? "Avis de securite",
      cve: advisory.cve ?? "-",
      affected: advisory.affectedVersions ?? "-",
      link: advisory.link ?? "",
    });
  }
}

const order = ["critical", "high", "moderate", "low", "unknown"];
// Toute vulnerabilite fait echouer le job, meme low/inconnue.
const blocking = rows.length;

const lines = [
  "### composer audit",
  "",
  "| Severite | Nombre |",
  "| --- | ---: |",
  ...order.map((severity) => `| ${severity} | ${counts[severity]} |`),
  "",
];

if (rows.length === 0) {
  lines.push("Aucune vulnerabilite connue detectee dans `composer.lock`.");
} else {
  rows.sort(
    (a, b) =>
      order.indexOf(a.severity) - order.indexOf(b.severity) ||
      a.packageName.localeCompare(b.packageName),
  );
  lines.push(
    "| Paquet | Severite | CVE | Versions affectees | Avis |",
    "| --- | --- | --- | --- | --- |",
    ...rows.map((row) => {
      const title = row.link ? `[${row.title}](${row.link})` : row.title;
      return `| \`${row.packageName}\` | ${row.severity} | ${row.cve} | \`${row.affected}\` | ${title} |`;
    }),
    "",
    "Correction manuelle : `composer update <paquet> --with-dependencies`, puis verifier",
    "`pint` et les tests, et committer soi-meme `composer.json` / `composer.lock`.",
  );
}

if (abandoned.length > 0) {
  lines.push(
    "",
    "### Paquets abandonnes",
    "",
    ...abandoned.map(
      ([name, replacement]) =>
        `- \`${name}\`${replacement ? ` → \`${replacement}\`` : ""}`,
    ),
  );
}

appendFileSync(process.env.GITHUB_STEP_SUMMARY ?? "/dev/stdout", `${lines.join("\n")}\n`);

if (blocking > 0) {
  console.error(
    `::error::${blocking} vulnerabilite(s) detectee(s), toutes severites confondues. Voir le resume du job pour la liste et les correctifs.`,
  );
  process.exitCode = 1;
}
