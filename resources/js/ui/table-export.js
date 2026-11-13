/**
 * Export a DataTable to Excel (.xlsx) or PDF.
 *
 *   exportTable(table, { format: 'xlsx' | 'pdf', title })
 *
 * Called from the table toolbar's Export menu (resources/js/ui/table-toolbar.js), so
 * every table has it. What is exported is what the user is looking at: the rows left by
 * the search and the filters, in the current sort order, ALL pages of them (not only the
 * page on screen), with the columns the table shows. A column is left out when its <th>
 * carries `data-no-export`: the running number, the action buttons, pictures and proof links.
 *
 * The cell text is the rendered cell with its markup removed (a status badge becomes its
 * label, the lines of an order become one per line), so the file reads like the table.
 *
 * The two writers are big and rarely used, so they are imported on demand: a page that
 * never exports never downloads them.
 */
import { showToast } from './toast';

const today = () => new Date().toLocaleDateString('en-CA');

const slug = (text) => String(text ?? 'export').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'export';

// The text of a piece of rendered cell markup. Block level pieces (a div, a paragraph, a
// span with the `block` class, a line break) start a new line.
const textOf = (html) => {
    const holder = document.createElement('div');

    holder.innerHTML = String(html ?? '');

    const lines = [];
    let current = '';

    const flush = () => {
        const line = current.replace(/\s+/g, ' ').trim();

        if (line !== '') {
            lines.push(line);
        }

        current = '';
    };

    const walk = (node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            current += node.textContent;

            return;
        }

        if (node.nodeType !== Node.ELEMENT_NODE) {
            return;
        }

        const block = node.matches('div, p, li, br, tr, dt, dd') || node.classList.contains('block');

        if (block) {
            flush();
        }

        node.childNodes.forEach(walk);

        if (block) {
            flush();
        }
    };

    holder.childNodes.forEach(walk);
    flush();

    return lines.join('\n');
};

// The visible, exportable columns and every row of the table as text.
const collect = (table) => {
    const columns = table.columns().indexes().toArray()
        .filter((index) => !table.column(index).header().hasAttribute('data-no-export'))
        .map((index) => ({ index, title: textOf(table.column(index).header().innerHTML) }));

    const rows = table.rows({ search: 'applied', order: 'applied' }).indexes().toArray()
        .map((rowIndex) => columns.map((column) => textOf(table.cell(rowIndex, column.index).render('display'))));

    return { headers: columns.map((column) => column.title), rows };
};

const download = (blob, filename) => {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();

    // Give the browser a moment to start the download before the URL goes.
    setTimeout(() => URL.revokeObjectURL(url), 4000);
};

const writeXlsx = async ({ headers, rows, title, filename }) => {
    const { default: writeExcelFile } = await import('write-excel-file/universal');

    const sheetData = [
        headers.map((header) => ({ value: header, fontWeight: 'bold', backgroundColor: '#e8e6e1', alignVertical: 'center' })),
        ...rows.map((row) => row.map((value) => ({ value, type: String, wrap: true, alignVertical: 'top' }))),
    ];

    // Width in characters: the longest line of the column, within a sensible range.
    const columns = headers.map((header, index) => {
        const longest = Math.max(header.length, ...rows.map((row) => Math.max(0, ...String(row[index]).split('\n').map((line) => line.length))));

        return { width: Math.min(Math.max(longest + 2, 10), 50) };
    });

    const blob = await writeExcelFile(sheetData, {
        columns,
        stickyRowsCount: 1,
        // Sheet names are limited to 31 characters and a few symbols.
        sheet: title.replace(/[\\/?*[\]:]/g, ' ').slice(0, 31) || 'Export',
    }).toBlob();

    download(blob, filename + '.xlsx');
};

const writePdf = async ({ headers, rows, title, filename }) => {
    const [{ jsPDF }, { autoTable }] = await Promise.all([import('jspdf'), import('jspdf-autotable')]);

    // Wide tables need the page on its side.
    const doc = new jsPDF({ orientation: headers.length > 5 ? 'landscape' : 'portrait', unit: 'pt', format: 'a4' });
    const width = doc.internal.pageSize.getWidth();
    const height = doc.internal.pageSize.getHeight();

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(16);
    doc.text(title, 40, 44);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(110);
    doc.text(rows.length + (rows.length === 1 ? ' record' : ' records') + '  ·  ' + today(), 40, 60);

    // The PDF's built-in Helvetica has no peso sign (it would print as a stray glyph), so amounts
    // are written "PHP 120.00" there. The Excel file keeps the real ₱.
    const forPdf = (cell) => (typeof cell === 'string' ? cell.replace(/₱\s?/g, 'PHP ') : cell);

    autoTable(doc, {
        head: [headers.map(forPdf)],
        body: rows.map((row) => row.map(forPdf)),
        startY: 74,
        margin: { left: 40, right: 40, bottom: 40 },
        styles: { fontSize: 8, cellPadding: 4, overflow: 'linebreak', valign: 'top', textColor: 40, lineColor: 225, lineWidth: 0.4 },
        // The ink of the app's primary buttons, on white text.
        headStyles: { fillColor: [24, 22, 18], textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [247, 246, 243] },
        // A repeated header on every page, and the page number under it.
        didDrawPage: () => {
            doc.setFontSize(8);
            doc.setTextColor(130);
            doc.text('Page ' + doc.getNumberOfPages(), width - 40, height - 20, { align: 'right' });
        },
    });

    doc.save(filename + '.pdf');
};

export async function exportTable(table, { format = 'xlsx', title = document.title } = {}) {
    const { headers, rows } = collect(table);

    if (rows.length === 0) {
        showToast('There is nothing to export.', 'warning');

        return;
    }

    const options = { headers, rows, title, filename: slug(title) + '-' + today() };

    try {
        if (format === 'pdf') {
            await writePdf(options);
        } else {
            await writeXlsx(options);
        }
    } catch (error) {
        console.error('The export failed.', error);
        showToast('The export could not be created.', 'error');
    }
}
