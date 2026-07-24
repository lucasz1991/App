import fs from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { FileBlob, SpreadsheetFile } from '@oai/artifact-tool';

const source = new URL('./output/app-export-sample.xlsx', import.meta.url);
const outputDir = new URL('./output/', import.meta.url);
const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(fileURLToPath(source)));

for (const [sheetName, range] of [
  ['Wagenliste', 'A1:V55'],
  ['Bremszettel', 'A1:G39'],
]) {
  const audit = await workbook.inspect({
    kind: 'table,formula',
    sheetId: sheetName,
    range,
    maxChars: 18000,
    tableMaxRows: 60,
    tableMaxCols: 24,
    options: { maxResults: 200 },
  });
  console.log(`--- ${sheetName} ---`);
  console.log(audit.ndjson);

  const preview = await workbook.render({
    sheetName,
    range,
    scale: 1.4,
    format: 'png',
  });
  await fs.writeFile(
    new URL(`export-${sheetName.toLowerCase()}.png`, outputDir),
    new Uint8Array(await preview.arrayBuffer()),
  );
}

const keyValues = await workbook.inspect({
  kind: 'table,formula',
  sheetId: 'Wagenliste',
  range: 'B2:V55',
  maxChars: 10000,
  tableMaxRows: 60,
  tableMaxCols: 22,
  options: { maxResults: 120 },
});

if (!keyValues.ndjson.includes('RT 4711') || !keyValues.ndjson.includes('Habbiins')) {
  throw new Error('Die exportierten Wagenlistendaten fehlen im Workbook-Audit.');
}

const formulaErrors = await workbook.inspect({
  kind: 'match',
  searchTerm: '#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A',
  options: { useRegex: true, maxResults: 100 },
  summary: 'final formula error scan',
});

console.log('--- Formula errors ---');
console.log(formulaErrors.ndjson);
