import fs from 'node:fs/promises';
import { FileBlob, SpreadsheetFile } from '@oai/artifact-tool';

const source = 'E:/projekte/RailTime/Exel-Listen/RT-WAGENLISTE_optimiert_2.xlsx';
const outputDir = new URL('./output/', import.meta.url);

await fs.mkdir(outputDir, { recursive: true });

const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(source));

const overview = await workbook.inspect({
  kind: 'workbook,sheet,region,formula,definedName',
  maxChars: 12000,
  tableMaxRows: 12,
  tableMaxCols: 24,
  options: { maxResults: 160 },
});
console.log(overview.ndjson);

for (const sheetName of ['Wagenliste', 'Bremszettel']) {
  const range = sheetName === 'Wagenliste' ? 'A1:V55' : 'A1:G39';
  const values = await workbook.inspect({
    kind: 'table,formula,computedStyle',
    sheetId: sheetName,
    range,
    maxChars: 18000,
    tableMaxRows: 60,
    tableMaxCols: 24,
    options: { maxResults: 200 },
  });
  console.log(`--- ${sheetName} ---`);
  console.log(values.ndjson);

  const preview = await workbook.render({
    sheetName,
    range,
    scale: 1.4,
    format: 'png',
  });
  await fs.writeFile(
    new URL(`${sheetName.toLowerCase()}.png`, outputDir),
    new Uint8Array(await preview.arrayBuffer()),
  );
}

const summaryFormulas = await workbook.inspect({
  kind: 'table,formula',
  sheetId: 'Wagenliste',
  range: 'H48:O55',
  maxChars: 8000,
  tableMaxRows: 12,
  tableMaxCols: 10,
  options: { maxResults: 80 },
});
console.log('--- Wagenliste summaries ---');
console.log(summaryFormulas.ndjson);
