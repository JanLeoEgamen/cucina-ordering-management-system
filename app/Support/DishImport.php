<?php

namespace App\Support;

use App\Models\Dish;
use App\Models\DishType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

// Importing dishes from an Excel file (Dish Masterlist -> Import). One workbook, three sheets:
//   Instructions  the steps,
//   Dish Types    the first table: the types that exist (pre-filled) and any NEW type typed below them,
//   Dishes        the second table: one dish per row; its "Dish Types" cell is a dropdown fed by the
//                 Dish Types sheet, so a type typed there shows up in the dropdown.
// So a single import creates the new dish types AND the dishes that use them.
//
// The template comes blank, or FILLED with the current menu (an ID column then says which dish a row is).
// Two modes on upload: MODE_ADD adds the new dishes and skips (lists) the ones already on the menu;
// MODE_UPDATE also updates the existing ones (matched by ID, else by name). MODE_REPLACE does the same
// and then makes the file THE menu: a dish the file does not carry is deleted (soft delete, like the
// Delete button; a dish that is in the file keeps its id, so its sessions and orders stay linked).
//
// `template()` builds the .xlsx staff download; `read()` turns an uploaded one into rows; `store()` saves
// them. The import is all or nothing: `read()` judges EVERY row of both tables first and reports every
// problem with its row number, so a bad file never leaves half a menu behind. Pictures are not part of
// the file (a spreadsheet cell cannot hold one safely): staff add them afterwards from the dish's Edit drawer.
class DishImport
{
    // What an upload does with a dish that is already on the menu.
    public const MODE_ADD = 'add';

    public const MODE_UPDATE = 'update';

    public const MODE_REPLACE = 'replace';

    // Most dishes one file may carry (a whole menu fits; keeps the request small).
    public const MAX_ROWS = 2000;

    // Most rows of the Dish Types table (also the range the Dishes dropdown reads).
    public const MAX_TYPES = 300;

    // Problems listed back to the person; the rest is summarised ("and 12 more").
    public const MAX_REPORTED = 25;

    public const SHEET_DISHES = 'Dishes';

    public const SHEET_TYPES = 'Dish Types';

    // The columns of the Dishes sheet, in the order of the template: key => [heading, required].
    public const COLUMNS = [
        'dish_id' => ['ID', false], // only in the filled-in template: which existing dish the row is
        'dish_name' => ['Dish Name', true],
        'dish_types' => ['Dish Types', true],
        'price' => ['Price', true],
        'description' => ['Description', false],
        'calories' => ['Calories', false],
        'main_ingredients' => ['Main Ingredients', false],
        'includes_rice' => ['Comes With Rice', false],
        'is_active' => ['Available', false],
    ];

    // Other headings people type, matched after normalising (see normalise()).
    private const ALIASES = [
        'dishid' => 'dish_id',
        'dishtype' => 'dish_types',
        'type' => 'dish_types',
        'types' => 'dish_types',
        'name' => 'dish_name',
        'dish' => 'dish_name',
        'rice' => 'includes_rice',
        'withrice' => 'includes_rice',
        'active' => 'is_active',
    ];

    // The columns of the Dish Types sheet.
    private const TYPE_ALIASES = [
        'dishtype' => 'type_name',
        'dishtypes' => 'type_name',
        'type' => 'type_name',
        'name' => 'type_name',
        'typename' => 'type_name',
        'description' => 'description',
    ];

    /* -------- Template -------- */

    // The file staff download. `$types` are the available dish types: they are listed on the Dish Types
    // sheet so the dropdown works at once; the person adds any new type below them. `$dishes` (from
    // `Dish::forImport()`) fills the Dishes sheet with the current menu and adds the ID column; null is
    // the blank template.
    public static function template(Collection $types, ?array $dishes = null): Spreadsheet
    {
        $book = new Spreadsheet();

        // A sheet is styled only once it belongs to the workbook, so each one comes from it.
        self::instructionsSheet($book->getActiveSheet(), $dishes !== null);
        self::typesSheet($book->createSheet(), $types);
        self::dishesSheet($book->createSheet(), $dishes);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    private static function header(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '374151']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private static function instructionsSheet(Worksheet $sheet, bool $current): void
    {
        $sheet->setTitle('Instructions');

        $lines = [
            ['How to import dishes', null],
            [null, null],
            ['Step 1', 'Open the "Dish Types" sheet. Your current types are already there. To add a NEW type, type its name on the next empty row (leave no empty rows in between). Description is optional.'],
            $current
                ? ['Step 2', 'Open the "Dishes" sheet. Your current dishes are already listed. Change a cell to UPDATE that dish, or type NEW dishes on the empty rows below. Do not change the grey ID column: it tells the app which dish a row is (leave it empty on a new dish). In "Dish Types", pick from the dropdown; for more than one type, type them with commas, like: Main Course, Chicken'
                    . ' To leave a dish as it is, leave its row alone.']
                : ['Step 2', 'Open the "Dishes" sheet and fill in one dish per row, from row 2. In "Dish Types", pick from the dropdown: it lists the types of the "Dish Types" sheet. For more than one type, type them with commas, like: Main Course, Chicken'],
            ['Step 3', 'Save the file as .xlsx.'],
            ['Step 4', 'In the app: Dish Masterlist > Import > choose what to do ("Add new dishes only" or "Add new and update existing") > choose the file > Check file > Confirm import.'],
            [null, null],
            ['Good to know', null],
            ['*', 'Columns marked * are required: Dish Name, Dish Types and Price.'],
            ['Yes / No', 'Comes With Rice and Available are dropdowns. Left empty, a dish does NOT come with rice and IS available.'],
            ['Checked first', 'The app checks the file and tells you what it would add and update before anything is saved. If any row has a problem, nothing is imported and you are told which rows to fix.'],
            ['Add or update', '"Add new dishes only" skips a dish whose name is already on the menu, so uploading the same file twice does not double your menu. "Add new and update existing" also changes the dishes that are already there. An import never deletes a dish.'],
            ['Also', 'Type plain values, not formulas. Pictures are not imported: add them later with Edit on the dish. Existing dish types are never changed by an import.'],
        ];

        foreach ($lines as $index => [$a, $b]) {
            self::put($sheet, 'A' . ($index + 1), $a);
            self::put($sheet, 'B' . ($index + 1), $b);
        }

        $last = count($lines);

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A3:A' . $last)->getFont()->setBold(true);
        $sheet->getStyle('A8')->getFont()->setSize(12);
        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getColumnDimension('B')->setWidth(110);
        $sheet->getStyle('B3:B' . $last)->getAlignment()->setWrapText(true);
        $sheet->getStyle('A3:B' . $last)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    }

    // A cell written as TEXT: a dish named "=1+1" must come out as that text, never as a formula (a
    // spreadsheet runs formulas), and a number stays a number. Null leaves the cell empty.
    private static function put(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (is_int($value) || is_float($value)) {
            $sheet->setCellValue($coordinate, $value);

            return;
        }

        $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
    }

    private static function typesSheet(Worksheet $sheet, Collection $types): void
    {
        $sheet->setTitle(self::SHEET_TYPES);

        $sheet->setCellValue('A1', 'Dish Type *');
        $sheet->setCellValue('B1', 'Description');
        self::header($sheet, 'A1:B1');

        foreach ($types->values() as $index => $type) {
            self::put($sheet, 'A' . ($index + 2), $type->type_name);
        }

        $sheet->setCellValue('D1', 'Add new dish types on the next empty row. They then appear in the Dish Type dropdown of the "Dishes" sheet.');
        $sheet->getStyle('D1')->getFont()->setItalic(true);
        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(50);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->freezePane('A2');
    }

    // The Dishes sheet. The column letters come from COLUMNS (the ID column only exists when the sheet is
    // filled with the current menu), so a column added later cannot get out of step with its validation.
    private static function dishesSheet(Worksheet $sheet, ?array $dishes): void
    {
        $sheet->setTitle(self::SHEET_DISHES);

        $columns = $dishes === null ? array_diff_key(self::COLUMNS, ['dish_id' => true]) : self::COLUMNS;

        $letters = [];

        foreach (array_keys($columns) as $position => $key) {
            $letters[$key] = Coordinate::stringFromColumnIndex($position + 1);
            $sheet->setCellValue($letters[$key] . '1', $columns[$key][0] . ($columns[$key][1] ? ' *' : ''));
        }

        $widths = ['dish_id' => 8, 'dish_name' => 28, 'dish_types' => 30, 'price' => 12, 'description' => 40, 'calories' => 12, 'main_ingredients' => 36, 'includes_rice' => 18, 'is_active' => 14];

        foreach ($letters as $key => $letter) {
            $sheet->getColumnDimension($letter)->setWidth($widths[$key]);
        }

        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->freezePane('A2');

        // Room for every dish of the file plus the empty rows to add new ones in.
        $lastRow = max(self::MAX_ROWS, count($dishes ?? [])) + 1;

        foreach (array_values($dishes ?? []) as $index => $dish) {
            $row = $index + 2;

            self::put($sheet, $letters['dish_id'] . $row, $dish['dish_id']);
            self::put($sheet, $letters['dish_name'] . $row, $dish['dish_name']);
            self::put($sheet, $letters['dish_types'] . $row, implode(', ', $dish['type_names']));
            self::put($sheet, $letters['price'] . $row, (float) $dish['price']);
            self::put($sheet, $letters['description'] . $row, $dish['description']);
            self::put($sheet, $letters['calories'] . $row, $dish['calories']);
            self::put($sheet, $letters['main_ingredients'] . $row, $dish['main_ingredients']);
            self::put($sheet, $letters['includes_rice'] . $row, $dish['includes_rice'] ? 'Yes' : 'No');
            self::put($sheet, $letters['is_active'] . $row, $dish['is_active'] ? 'Yes' : 'No');
        }

        // Styles go on the WHOLE column (one entry), not on a range of rows: a range makes a cell object for
        // every row, which is megabytes for a 2,000 row sheet. The header, styled after, keeps its own look.
        if (isset($letters['dish_id'])) {
            // Grey and slanted: "this one is not for you to change".
            $sheet->getStyle($letters['dish_id'] . ':' . $letters['dish_id'])->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
            ]);
        }

        // Price as a number with two decimals; the dropdowns and limits stop typos at the cell.
        $sheet->getStyle($letters['price'] . ':' . $letters['price'])->getNumberFormat()->setFormatCode('0.00');

        self::header($sheet, 'A1:' . end($letters) . '1');
        $range = fn (string $key) => $letters[$key] . '2:' . $letters[$key] . $lastRow;

        // Dish Types is a dropdown of the Dish Types sheet (as many names as are filled in, no blanks at the
        // end). A dish may have SEVERAL types in one cell ("Mains, Chicken"), which a strict list would
        // refuse, so it only informs: OK keeps what was typed.
        $source = "OFFSET('" . self::SHEET_TYPES . "'!\$A\$2,0,0,MAX(1,COUNTA('" . self::SHEET_TYPES . "'!\$A\$2:\$A\$" . (self::MAX_TYPES + 1) . ")),1)";

        self::validation(
            $sheet,
            $range('dish_types'),
            DataValidation::TYPE_LIST,
            $source,
            null,
            'Pick a type from the list. For more than one type, type them separated by commas (Main Course, Chicken) and press OK. A new type must be added to the Dish Types sheet first.',
            DataValidation::STYLE_INFORMATION,
        );
        self::validation($sheet, $range('includes_rice'), DataValidation::TYPE_LIST, '"Yes,No"', null, 'Pick Yes or No.');
        self::validation($sheet, $range('is_active'), DataValidation::TYPE_LIST, '"Yes,No"', null, 'Pick Yes or No.');
        self::validation($sheet, $range('price'), DataValidation::TYPE_DECIMAL, '0', '99999999.99', 'The price must be a number of 0 or more.');
        self::validation($sheet, $range('calories'), DataValidation::TYPE_WHOLE, '0', '100000', 'Calories must be a whole number of 0 or more.');
    }

    private static function validation(Worksheet $sheet, string $range, string $type, string $first, ?string $second, string $message, string $style = DataValidation::STYLE_STOP): void
    {
        $rule = new DataValidation();
        $rule->setType($type)
            ->setErrorStyle($style)
            ->setAllowBlank(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Check this cell')
            ->setError($message)
            ->setFormula1($first);

        if ($type === DataValidation::TYPE_LIST) {
            $rule->setShowDropDown(true);
        } else {
            $rule->setOperator(DataValidation::OPERATOR_BETWEEN)->setFormula2($second);
        }

        $sheet->setDataValidation($range, $rule);
    }

    /* -------- Reading -------- */

    // Read an uploaded .xlsx. Returns ['rows' => dishes, 'new_types' => types to create, 'errors' => [...]];
    // when there is any error the caller must store nothing. `$canCreateTypes`: may this person add dish types?
    // `$mode`: MODE_ADD (dishes already on the menu are skipped), MODE_UPDATE (they are updated) or
    // MODE_REPLACE (updated, and the ones the file does not carry are removed).
    public static function read(string $path, bool $canCreateTypes = true, string $mode = self::MODE_ADD): array
    {
        try {
            $reader = new XlsxReader();
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);

            if (! $reader->canRead($path)) {
                return self::failed('This is not an Excel (.xlsx) file. Download the template and fill it in.');
            }

            // Formulas are never calculated: a formula in a file from outside must not run anything
            // (it is refused cell by cell below).
            $book = $reader->load($path);

            $dishes = $book->getSheetByName(self::SHEET_DISHES);

            if (! $dishes) {
                return self::failed('The file has no "' . self::SHEET_DISHES . '" sheet. Download the template again and keep its sheets.');
            }

            $dishGrid = self::grid($dishes, self::MAX_ROWS, 'dishes');

            $types = $book->getSheetByName(self::SHEET_TYPES);
            $typeGrid = $types ? self::grid($types, self::MAX_TYPES, 'dish types') : [];
        } catch (\LengthException $problem) {
            // Our own "too big" answers.
            return self::failed($problem->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Dish import: the file could not be read.', ['error' => $exception->getMessage()]);

            return self::failed('The file could not be read. Save it as an Excel workbook (.xlsx), or download the template again.');
        }

        return self::judge($dishGrid, $typeGrid, $canCreateTypes, $mode);
    }

    // A sheet's cells as a plain array (row 1 = the headings), after a size check. Formulas are not calculated.
    private static function grid(Worksheet $sheet, int $maxRows, string $what): array
    {
        $lastRow = $sheet->getHighestDataRow();
        $lastColumn = $sheet->getHighestDataColumn();

        if ($lastRow - 1 > $maxRows) {
            throw new \LengthException('The file has more than ' . $maxRows . ' ' . $what . '. Split it into smaller files.');
        }

        if (strlen($lastColumn) > 1) {
            throw new \LengthException('The file has too many columns. Download the template and use it as it is.');
        }

        return $sheet->rangeToArray('A1:' . $lastColumn . $lastRow, null, false, false, false);
    }

    // Judge both tables (row 1 of each = the headings). Public so tests can feed it plain arrays.
    //
    // Returns ['rows' => dishes to add, 'updates' => dishes to change, 'new_types' => types to create,
    // 'skipped' => names left alone because they are on the menu (add mode), 'unchanged' => how many rows
    // matched a dish that already reads the same, 'errors' => [...]]. With any error the rest is empty.
    public static function judge(array $dishGrid, array $typeGrid = [], bool $canCreateTypes = true, string $mode = self::MODE_ADD): array
    {
        $columns = self::locateColumns(array_shift($dishGrid) ?? [], self::headingMap());

        $missing = array_filter(
            array_keys(array_filter(self::COLUMNS, fn (array $column) => $column[1])),
            fn (string $key) => ! isset($columns[$key]),
        );

        if ($missing !== []) {
            $names = array_map(fn (string $key) => self::COLUMNS[$key][0], $missing);

            return self::failed('Column ' . implode(', ', $names) . ' is missing on the Dishes sheet. Download the template again and keep its headings in row 1.');
        }

        $replace = $mode === self::MODE_REPLACE;
        $update = $mode !== self::MODE_ADD; // update and replace both change the dishes that exist

        // The first table: which types the dishes may use, and which of them are new.
        $known = DishType::namesInUse();

        [$usable, $newTypes, $errors] = self::judgeTypes($typeGrid, $known, $canCreateTypes);

        // The menu as it is now: which dish a row is, and whether it really changes anything.
        $live = Dish::forImport();

        $byName = [];

        foreach ($live as $id => $dish) {
            $byName[self::fold($dish['dish_name'])] = $id;
        }

        $adds = [];
        $updates = [];
        $skipped = [];
        $unchanged = 0;
        $seen = [];      // dish id => the sheet row that already named it
        $fileRows = [];  // every accepted row: [sheet row, dish id or null, name], to check the names afterwards

        foreach ($dishGrid as $index => $cells) {
            $number = $index + 2; // the sheet's own row number (row 1 was the headings)

            // A column the file does not have (the optional ones) reads as empty.
            $values = [];

            foreach (array_keys(self::COLUMNS) as $key) {
                $values[$key] = isset($columns[$key]) ? self::text($cells[$columns[$key]] ?? null) : '';
            }

            // A row with nothing in it is just spacing.
            if (implode('', $values) === '') {
                continue;
            }

            // Which dish on the menu is this row? By its ID when it has one, else by its name.
            $match = null;
            $problems = [];

            if ($values['dish_id'] !== '') {
                if (! ctype_digit($values['dish_id'])) {
                    $problems[] = 'ID must be the number of the template. Leave it empty for a new dish.';
                } elseif (! isset($live[(int) $values['dish_id']])) {
                    $problems[] = 'ID ' . $values['dish_id'] . ' is not a dish on the menu (was it deleted?). Download the template again.';
                } else {
                    $match = (int) $values['dish_id'];
                }
            } elseif ($values['dish_name'] !== '' && isset($byName[self::fold($values['dish_name'])])) {
                $match = $byName[self::fold($values['dish_name'])];
            }

            if ($match !== null && isset($seen[$match])) {
                $problems[] = 'This dish is already on row ' . $seen[$match] . '.';
            }

            if ($problems) {
                $errors[] = 'Row ' . $number . ': ' . implode(' ', $problems);

                continue;
            }

            if ($match !== null) {
                $seen[$match] = $number;
            }

            // Add mode leaves a dish that is already there alone (it is listed, not an error).
            if ($match !== null && ! $update) {
                $skipped[] = $live[$match]['dish_name'];

                continue;
            }

            // A column the file does not have is not something the person decided: the dish keeps its value
            // (a short file that only carries names, types and prices changes only the prices). A column
            // that IS in the file speaks for itself, an empty cell included.
            if ($match !== null) {
                foreach (self::keptColumns($live[$match]) as $key => $stored) {
                    if (! isset($columns[$key])) {
                        $values[$key] = $stored;
                    }
                }
            }

            [$row, $problems] = self::judgeRow($values, $usable, $known, $match === null ? [] : $live[$match]['types']);

            if ($problems) {
                $errors[] = 'Row ' . $number . ': ' . implode(' ', $problems);

                continue;
            }

            $fileRows[] = [$number, $match, $row['dish_name']];

            if ($match === null) {
                $adds[] = $row;

                continue;
            }

            $changes = self::changed($live[$match], $row);

            if ($changes === []) {
                $unchanged++;

                continue;
            }

            // `dish_types` null = the types are left exactly as they are (nothing to rewrite).
            $updates[] = ['dish_id' => $match, ...$row, 'dish_types' => in_array('dish_types', $changes, true) ? $row['dish_types'] : null];
        }

        // Two dishes may not end up with one name: a new dish with the name of another, a dish renamed
        // to a name that is taken, two rows with one name. A name freed by a rename in this same file counts as free.
        // In replace mode the dishes the file does not carry are the ones that go: their names are free too.
        $removed = [];

        if ($replace) {
            foreach (array_diff_key($live, $seen) as $id => $dish) {
                $removed[$id] = $dish['dish_name'];
            }
        }

        $errors = array_merge($errors, self::nameClashes($live, $fileRows, array_keys($removed)));

        // A replace that carries no dish (a file with only types, or an empty one) would empty the menu.
        if ($replace && $fileRows === [] && $errors === []) {
            return self::failed('Replacing the menu needs at least one dish in the file, or the menu would be emptied. Fill in the Dishes sheet from row 2.');
        }

        if ($adds === [] && $updates === [] && $newTypes === [] && $skipped === [] && $unchanged === 0 && $errors === []) {
            return self::failed('The file has no dishes. Fill in the Dishes sheet from row 2.');
        }

        if (count($errors) > self::MAX_REPORTED) {
            $more = count($errors) - self::MAX_REPORTED;
            $errors = array_slice($errors, 0, self::MAX_REPORTED);
            $errors[] = 'and ' . $more . ' more row' . ($more === 1 ? '' : 's') . ' with problems.';
        }

        return $errors
            ? self::failed(...$errors)
            : ['rows' => $adds, 'updates' => $updates, 'new_types' => $newTypes, 'skipped' => $skipped, 'unchanged' => $unchanged, 'removed' => $removed, 'errors' => []];
    }

    // The optional columns of a dish on the menu, as the text a cell would hold.
    private static function keptColumns(array $stored): array
    {
        return [
            'description' => (string) ($stored['description'] ?? ''),
            'calories' => $stored['calories'] === null ? '' : (string) $stored['calories'],
            'main_ingredients' => (string) ($stored['main_ingredients'] ?? ''),
            'includes_rice' => $stored['includes_rice'] ? 'yes' : 'no',
            'is_active' => $stored['is_active'] ? 'yes' : 'no',
        ];
    }

    // What differs between a dish on the menu and its row of the file: the changed column names (empty
    // = identical). The types are compared as sets of ids; a type the file creates is always a change.
    private static function changed(array $stored, array $row): array
    {
        $differs = [];

        if ($stored['dish_name'] !== $row['dish_name']) {
            $differs[] = 'dish_name';
        }

        foreach (['description', 'main_ingredients'] as $text) {
            if ((string) ($stored[$text] ?? '') !== (string) ($row[$text] ?? '')) {
                $differs[] = $text;
            }
        }

        if (number_format((float) $stored['price'], 2, '.', '') !== number_format((float) $row['price'], 2, '.', '')) {
            $differs[] = 'price';
        }

        if ($stored['calories'] !== $row['calories']) {
            $differs[] = 'calories';
        }

        foreach (['includes_rice', 'is_active'] as $flag) {
            if ($stored[$flag] !== $row[$flag]) {
                $differs[] = $flag;
            }
        }

        $new = $row['dish_types'];
        $old = array_values($stored['types']);

        if (array_filter($new, 'is_string') !== []) {
            $differs[] = 'dish_types'; // a type the file creates cannot be what the dish has now
        } else {
            sort($new);
            sort($old);

            if ($new !== $old) {
                $differs[] = 'dish_types';
            }
        }

        return $differs;
    }

    // The names clash after the file is applied: every dish keeps its name unless the file renames it,
    // then any name held twice is reported on the file's row(s) that hold it.
    private static function nameClashes(array $live, array $fileRows, array $removedIds = []): array
    {
        $removedIds = array_flip($removedIds);

        $claims = [];
        $inFile = [];

        foreach ($fileRows as [$number, $id, $name]) {
            $claims[self::fold($name)][] = [$number, $name];

            if ($id !== null) {
                $inFile[$id] = true;
            }
        }

        foreach ($live as $id => $dish) {
            // A dish that is going away no longer holds its name.
            if (! isset($inFile[$id]) && ! isset($removedIds[$id])) {
                $claims[self::fold($dish['dish_name'])][] = [null, $dish['dish_name']];
            }
        }

        $errors = [];

        foreach ($claims as $holders) {
            if (count($holders) < 2) {
                continue;
            }

            foreach ($holders as [$number, $name]) {
                if ($number !== null) {
                    $errors[$number] = 'Row ' . $number . ': a dish named "' . $name . '" would exist twice. Dish names must be different.';
                }
            }
        }

        ksort($errors);

        return array_values($errors);
    }

    // The Dish Types table. Returns [usable, newTypes, errors]:
    //  - usable: lower case name => the id of an available type, or 'new:<name>' for one this file adds;
    //  - newTypes: [{type_name, description}] to create;
    //  - errors: one line per bad row.
    private static function judgeTypes(array $grid, array $known, bool $canCreate): array
    {
        $usable = [];

        // Every available type can be used by a dish, listed on the sheet or not.
        foreach ($known as $name => $type) {
            if ($type['active'] && ! $type['deleted']) {
                $usable[$name] = $type['id'];
            }
        }

        $new = [];
        $errors = [];

        $columns = self::locateColumns(array_shift($grid) ?? [], self::TYPE_ALIASES);

        if ($grid !== [] && ! isset($columns['type_name'])) {
            return [$usable, [], ['The Dish Types sheet needs the heading "Dish Type" in cell A1. Download the template again.']];
        }

        foreach ($grid as $index => $cells) {
            $number = $index + 2;
            $name = self::text($cells[$columns['type_name']] ?? null);
            $description = isset($columns['description']) ? self::text($cells[$columns['description']] ?? null) : '';

            if ($name === '' && $description === '') {
                continue;
            }

            $where = 'Dish Types sheet, row ' . $number . ': ';

            if ($name === '') {
                $errors[] = $where . 'the dish type needs a name.';
            } elseif (str_starts_with($name . $description, '=')) {
                $errors[] = $where . 'a cell holds a formula; type the value instead.';
            } elseif (mb_strlen($name) > 255 || mb_strlen($description) > 1000) {
                $errors[] = $where . 'the name may have 255 characters and the description 1000.';
            } elseif (isset($known[self::fold($name)])) {
                $type = $known[self::fold($name)];

                if ($type['deleted']) {
                    $errors[] = $where . '"' . $name . '" was a dish type that has been deleted, so the name cannot be used again. Pick another name.';
                } elseif (! $type['active']) {
                    $errors[] = $where . '"' . $name . '" is turned off. Turn it on in Manage Types, or leave it out.';
                }
                // else: an existing available type, listed so the dropdown shows it. Nothing to do.
            } elseif (! isset($usable[self::fold($name)])) {
                // A new type (a repeat of the same name further down is simply ignored).
                if (! $canCreate) {
                    $errors[] = $where . 'you are not allowed to add dish types ("' . $name . '"). Ask for the "create dish-types" permission, or use an existing type.';
                } else {
                    $new[] = ['type_name' => $name, 'description' => $description === '' ? null : $description];
                    $usable[self::fold($name)] = 'new:' . self::fold($name);
                }
            }
        }

        return [$usable, $new, $errors];
    }

    // One dish row: the values ready to store, and the problems found (empty when it is fine). `$keep` is
    // the dish's own current types (lower case name => id) when the row updates a dish: a type it already
    // has stays valid even if it was turned off since, or the dish could never be re-imported.
    private static function judgeRow(array $values, array $usable, array $known, array $keep): array
    {
        $problems = [];

        foreach ($values as $key => $value) {
            if (str_starts_with($value, '=')) {
                $problems[] = self::COLUMNS[$key][0] . ' holds a formula; type the value instead.';
            }
        }

        if ($problems) {
            return [[], $problems];
        }

        $price = str_replace([',', '₱', ' '], '', $values['price']);

        $validator = Validator::make([
            'dish_name' => $values['dish_name'],
            'price' => $price,
            'description' => $values['description'] === '' ? null : $values['description'],
            'calories' => $values['calories'] === '' ? null : $values['calories'],
            'main_ingredients' => $values['main_ingredients'] === '' ? null : $values['main_ingredients'],
        ], [
            'dish_name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
            'calories' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'main_ingredients' => ['nullable', 'string', 'max:255'],
        ], [], [
            'dish_name' => 'Dish Name',
            'price' => 'Price',
            'description' => 'Description',
            'calories' => 'Calories',
            'main_ingredients' => 'Main Ingredients',
        ]);

        foreach ($validator->errors()->all() as $message) {
            $problems[] = $message;
        }

        // Dish types: each name must be an available type, or a new one listed on the Dish Types sheet.
        $names = array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[,;|\n]+/', $values['dish_types']) ?: []),
            fn (string $name) => $name !== '',
        )));

        $ids = [];
        $unknown = [];

        foreach ($names as $name) {
            $id = $usable[self::fold($name)] ?? $keep[self::fold($name)] ?? null;

            if ($id !== null) {
                $ids[(string) $id] = $id;
            } else {
                $unknown[] = $name;
            }
        }

        if ($names === []) {
            $problems[] = 'Dish Types is required: pick at least one.';
        } elseif ($unknown) {
            $off = array_filter($unknown, fn (string $name) => isset($known[self::fold($name)]));

            $problems[] = 'Dish type not available: ' . implode(', ', $unknown) . '. Pick it from the dropdown'
                . ($off ? ' (a type that is turned off or deleted cannot be used)' : ', or add it to the Dish Types sheet first')
                . '.';
        }

        $rice = self::yesNo($values['includes_rice'], false);
        $active = self::yesNo($values['is_active'], true);

        if ($rice === null) {
            $problems[] = 'Comes With Rice must be Yes or No.';
        }

        if ($active === null) {
            $problems[] = 'Available must be Yes or No.';
        }

        if ($problems) {
            return [[], $problems];
        }

        return [[
            'dish_name' => $values['dish_name'],
            'description' => $values['description'] === '' ? null : $values['description'],
            'price' => $price,
            'calories' => $values['calories'] === '' ? null : (int) $values['calories'],
            'main_ingredients' => $values['main_ingredients'] === '' ? null : $values['main_ingredients'],
            'includes_rice' => $rice,
            'is_active' => $active,
            'dish_types' => array_values($ids),
        ], []];
    }

    /* -------- Storing -------- */

    // Save what read() accepted, in ONE transaction: the new dish types first, then the new dishes, then
    // the changes to existing ones. Returns how many of each were stored. Throws DishGoneException when a
    // dish to update was deleted since the file was checked (nothing is stored then).
    public static function store(array $result): array
    {
        return DB::transaction(function () use ($result) {
            $typeIds = DishType::createMany($result['new_types']);

            // 'new:<name>' placeholders become the ids of the types just created.
            $resolve = fn (array $types) => array_map(fn ($type) => is_string($type) ? $typeIds[substr($type, 4)] : $type, $types);

            $rows = array_map(fn (array $row) => ['dish_types' => $resolve($row['dish_types'])] + $row, $result['rows']);

            $updates = array_map(
                fn (array $update) => $update['dish_types'] === null ? $update : ['dish_types' => $resolve($update['dish_types'])] + $update,
                $result['updates'] ?? [],
            );

            return [
                'dishes' => Dish::createMany($rows),
                'updated' => Dish::updateMany($updates),
                'removed' => Dish::deleteMany(array_keys($result['removed'] ?? [])),
                'types' => count($typeIds),
            ];
        });
    }

    // The sentence the person reads: `$done` false = what a check found ("2 dishes to add"), true = what was saved.
    public static function summary(array $result, bool $done): string
    {
        $adds = count($result['rows']);
        $updates = count($result['updates'] ?? []);
        $types = count($result['new_types']);
        $unchanged = (int) ($result['unchanged'] ?? 0);
        $skipped = $result['skipped'] ?? [];
        $removed = $result['removed'] ?? [];

        $dishes = fn (int $count) => $count . ($count === 1 ? ' dish' : ' dishes');
        $sentences = [];

        if ($done) {
            if ($adds > 0 || ($updates === 0 && $removed === [])) {
                $sentences[] = $dishes($adds) . ' imported.';
            }

            if ($types > 0) {
                $sentences[] = $types . ($types === 1 ? ' new dish type' : ' new dish types') . ' added.';
            }

            if ($updates > 0) {
                $sentences[] = $dishes($updates) . ' updated.';
            }

            if ($removed !== []) {
                $sentences[] = $dishes(count($removed)) . ' removed.';
            }
        } else {
            if ($adds + $updates + $types + count($removed) === 0) {
                $sentences[] = 'Nothing to import.';
            }

            if ($adds > 0) {
                $sentences[] = $dishes($adds) . ' to add.';
            }

            if ($updates > 0) {
                $sentences[] = $dishes($updates) . ' to update.';
            }

            if ($types > 0) {
                $sentences[] = $types . ($types === 1 ? ' new dish type' : ' new dish types') . ' to add.';
            }

            if ($removed !== []) {
                $names = array_values($removed);

                $sentences[] = $dishes(count($names)) . ' to REMOVE from the menu (' . implode(', ', array_slice($names, 0, 8)) . (count($names) > 8 ? ', ...' : '') . ').';
            }
        }

        if ($unchanged > 0) {
            $sentences[] = $dishes($unchanged) . ' unchanged.';
        }

        if ($skipped !== []) {
            $sentences[] = $dishes(count($skipped)) . ' skipped because already on the menu (' . implode(', ', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? ', ...' : '') . ').';
        }

        return implode(' ', $sentences);
    }

    /* -------- Cells -------- */

    private static function headingMap(): array
    {
        $byHeading = [];

        foreach (self::COLUMNS as $key => [$heading]) {
            $byHeading[self::normalise($heading)] = $key;
        }

        return $byHeading + self::ALIASES;
    }

    // Which sheet column holds which field, found from the headings in row 1 (so the order of the
    // columns does not matter, and "Dish Name *" or "dish name" both work).
    private static function locateColumns(array $headings, array $byHeading): array
    {
        $columns = [];

        foreach ($headings as $position => $heading) {
            $key = $byHeading[self::normalise(self::text($heading))] ?? null;

            if ($key !== null && ! isset($columns[$key])) {
                $columns[$key] = $position;
            }
        }

        return $columns;
    }

    // "Dish Name *" -> "dishname".
    private static function normalise(string $heading): string
    {
        return preg_replace('/[^a-z]/', '', strtolower($heading)) ?? '';
    }

    // Compare names without caring about case or surrounding spaces ("Mains" = "mains ").
    private static function fold(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    // Yes / No in the ways people write it; blank = the default; anything else = null (a problem).
    private static function yesNo(string $value, bool $default): ?bool
    {
        return match (strtolower($value)) {
            '' => $default,
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }

    // A cell as trimmed text. Whole numbers lose their ".0" (a calories cell reads 480, not 480.0).
    private static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        $text = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';

        // Spreadsheets love non-breaking spaces; trim them like ordinary ones.
        return trim(preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $text) ?? $text);
    }

    // Nothing to store: only these problems.
    private static function failed(string ...$messages): array
    {
        return ['rows' => [], 'updates' => [], 'new_types' => [], 'skipped' => [], 'unchanged' => 0, 'removed' => [], 'errors' => array_values($messages)];
    }
}
