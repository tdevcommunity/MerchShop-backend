// Resume lisible de `composer outdated --format=json` dans le resume du job.
// Echoue des qu'une dependance directe a une mise a jour disponible.
// Aucune correction automatique : le job constate, l'humain met a jour.
// Usage : node .github/scripts/composer-outdated-summary.mjs composer-outdated.json

import { readFileSync, appendFileSync } from "node:fs";

const reportPath = process.argv[2] ?? "composer-outdated.json";
const raw = readFileSync(reportPath, "utf8").trim();
const report = raw ? JSON.parse(raw) : {};
const packages = Array.isArray(report) ? report : (report.installed ?? report.locked ?? []);

const statusLabels = {
  "semver-safe-update": "compatible",
  "update-possible": "majeure",
  "up-to-date": "a jour",
};

function statusOf(pkg) {
  return statusLabels[pkg["latest-status"]] ?? pkg["latest-status"] ?? "inconnu";
}

const lines = [
  "### composer outdated",
  "",
];

if (packages.length === 0) {
  lines.push("Toutes les dependances directes sont a jour.");
} else {
  const rows = [...packages].sort((a, b) => a.name.localeCompare(b.name));
  lines.push(
    `${rows.length} dependance(s) directe(s) a mettre a jour :`,
    "",
    "| Paquet | Actuelle | Derniere | Etat |",
    "| --- | --- | --- | --- |",
    ...rows.map((pkg) => {
      const current = pkg.version ?? "-";
      const latest = pkg.latest ?? "-";
      return `| \`${pkg.name}\` | ${current} | ${latest} | ${statusOf(pkg)} |`;
    }),
    "",
    "Mise a jour manuelle, dans `merch_shop_api` :",
    "`composer update <paquet> --with-dependencies`, puis `composer validate --strict`,",
    "verifier `pint` et les tests, et committer soi-meme `composer.json` / `composer.lock`.",
  );
}

appendFileSync(process.env.GITHUB_STEP_SUMMARY ?? "/dev/stdout", `${lines.join("\n")}\n`);

if (packages.length > 0) {
  console.error(
    `::error::${packages.length} dependance(s) a mettre a jour. Voir le resume du job pour la liste des versions.`,
  );
  process.exitCode = 1;
}
