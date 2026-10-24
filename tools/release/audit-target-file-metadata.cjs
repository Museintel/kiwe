#!/usr/bin/env node
'use strict';
// Offline preparation only. Never reads a live website, file bodies or credentials.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const requireFact = value => { if (!value) throw new Error('Metadata review refused'); };
function read(file) {
  const stat = fs.lstatSync(file);
  requireFact(stat.isFile() && !stat.isSymbolicLink() && stat.size <= 8388608);
  const bytes = fs.readFileSync(file);
  return { bytes, value: JSON.parse(bytes.toString('utf8')) };
}
function pages(rows, offsetKey, totalKey) {
  requireFact(Array.isArray(rows) && rows.length > 0 && rows.length <= 256);
  rows = [...rows].sort((a, b) => a[offsetKey] - b[offsetKey]);
  let offset = 0;
  const total = rows[0][totalKey];
  requireFact(Number.isSafeInteger(total) && total > 0 && total <= 200000);
  for (const row of rows) {
    requireFact(row[offsetKey] === offset && row[totalKey] === total && Number.isSafeInteger(row.returned) && row.returned > 0 && row.returned <= 1000);
    offset += row.returned;
  }
  requireFact(offset === total);
}
function audit(input, quota) {
  const j = input.value;
  requireFact(j.schema === 'kiwe.proposed-woo-file-metadata-observation.v1' && typeof j.domain === 'string' && /^[a-z0-9.-]+\.[a-z]{2,63}$/.test(j.domain));
  requireFact(Array.isArray(j.entries) && j.entries.length > 0 && j.entries.length <= 170000 && j.rootRequestedDepth === 10);
  pages(j.rootPages, 'requestedOffset', 'reportedTotal');
  requireFact(Array.isArray(j.followedDepthBoundaryOwners) && new Set(j.followedDepthBoundaryOwners).size === j.followedDepthBoundaryOwners.length && j.followedDepthBoundaryOwners.length <= 256 && Array.isArray(j.followPages));
  for (const owner of j.followedDepthBoundaryOwners) pages(j.followPages.filter(row => row.directory === owner), 'offset', 'total');
  requireFact(j.followPages.every(row => j.followedDepthBoundaryOwners.includes(row.directory)) && j.totals.followBoundaryDirectories === 0 && j.totals.overlappingMetadataChanges === 0);
  const policyFile = path.resolve(__dirname, '../../wp-content/mu-plugins/dsa/includes/Backup/Portable_File_Inventory.php');
  const policyBytes = fs.readFileSync(policyFile), policy = policyBytes.toString('utf8');
  const declaration = policy.match(/private const EXCLUDED = \[([^\]]*)\];/);
  requireFact(declaration);
  const literals = [...declaration[1].matchAll(/'([^'\r\n]+)'\s*=>\s*'([^'\r\n]+)'/g)];
  requireFact(literals.length > 0 && declaration[1].replace(/'([^'\r\n]+)'\s*=>\s*'([^'\r\n]+)'/g, '').replace(/[\s,]/g, '') === '');
  const excluded = literals.map(row => row[1]);
  const totals = { entries: 0, files: 0, directories: 0, fileBytes: 0 };
  const estimate = { entries: 0, files: 0, directories: 0, fileBytes: 0 };
  const skipped = { entries: 0, files: 0, directories: 0, fileBytes: 0 };
  const paths = new Map();
  for (const entry of j.entries) {
    requireFact(Array.isArray(entry) && entry.length === 3);
    const [name, type, size] = entry;
    requireFact(typeof name === 'string' && name.length > 0 && Buffer.byteLength(name) <= 2048 && Buffer.from(name).toString('utf8') === name && !/[\x00-\x1f\x7f]/.test(name) && !name.split('/').some(part => !part || part === '.' || part === '..') && !paths.has(name));
    requireFact(type === 'file' || type === 'directory');
    requireFact(type === 'file' ? Number.isSafeInteger(size) && size >= 0 && size <= 2147483648 : size === null);
    paths.set(name, type);
    const dest = excluded.some(prefix => name === prefix || name.startsWith(prefix + '/')) ? skipped : estimate;
    for (const counter of [totals, dest]) { counter.entries++; counter[type === 'file' ? 'files' : 'directories']++; if (type === 'file') counter.fileBytes += size; }
  }
  for (const name of paths.keys()) {
    let parent = name.slice(0, name.lastIndexOf('/'));
    if (!name.includes('/')) continue;
    while (parent) { requireFact(paths.get(parent) === 'directory'); const slash = parent.lastIndexOf('/'); if (slash < 0) break; parent = parent.slice(0, slash); }
  }
  for (const [key, value] of Object.entries(totals)) requireFact(j.totals[key] === value);
  requireFact(j.totals.symlinks === 0 && j.totals.other === 0 && j.totals.rootPages === j.rootPages.length && j.totals.followPages === j.followPages.length);
  const q = quota.value.accountCapacity;
  requireFact(q && Number.isSafeInteger(q.usedFileEntries) && q.usedFileEntries >= 0 && Number.isSafeInteger(q.limitFileEntries) && q.limitFileEntries >= q.usedFileEntries && q.freeFileEntries === q.limitFileEntries - q.usedFileEntries);
  const minimum = estimate.entries * 2;
  const observedQuota = { scope: 'Previously recorded shared-account file-entry quota', usedFileEntries: q.usedFileEntries, limitFileEntries: q.limitFileEntries, freeFileEntries: q.freeFileEntries };
  return { schema: 'kiwe.target-file-metadata-capacity.v1', passed: true, kind: 'offline-metadata-review', domain: j.domain, inputSha256: hash(input.bytes), quotaInputSha256: hash(quota.bytes), policySourceSha256: hash(policyBytes), observedEntries: totals, currentCapturePolicyEstimate: estimate, currentPolicyExcludedMetadata: skipped, completeRootPages: j.rootPages.length, completeFollowPages: j.followPages.length, accountQuotaObservation: observedQuota, expandedSourceTreeEntryLowerBound: minimum, estimatedShortfallAgainstRecordedQuota: Math.max(0, minimum - q.freeFileEntries), expandedSourceBytesLowerBound: estimate.fileBytes * 2, actualFileHashesReviewed: false, atomicSnapshot: false, databaseBytesIncluded: false, freshExactQuotaRead: false, fullBackupAccepted: false, actualSourceRestoreExecuted: false, destinationQualified: false };
}
if (require.main === module) {
  try { requireFact(process.argv.length === 4); console.log(JSON.stringify(audit(read(process.argv[2]), read(process.argv[3])), null, 2)); }
  catch { console.log(JSON.stringify({ schema: 'kiwe.target-file-metadata-capacity.v1', passed: false, error: 'Offline metadata/capacity review refused' })); process.exitCode = 1; }
}
module.exports = { audit };
