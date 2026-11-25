<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishType;
use App\Support\DishImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

// Dish Masterlist -> Import: the Excel template and the all-or-nothing import of dishes with their types.
class DishImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = ['Dish Name *', 'Dish Types *', 'Price *', 'Description', 'Calories', 'Main Ingredients', 'Comes With Rice', 'Available'];

    // A real .xlsx upload: the first row is the headings, then one array per dish row.
    // `$typeRows` fills the "Dish Types" table ([name, description] per row, under its heading); null leaves the sheet out.
    private function workbook(array $rows, ?array $headings = null, string $name = 'dishes.xlsx', ?array $typeRows = null): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Dishes')->fromArray([$headings ?? self::HEADINGS, ...$rows], null, 'A1', true);

        if ($typeRows !== null) {
            $book->createSheet()->setTitle('Dish Types')->fromArray([['Dish Type *', 'Description'], ...$typeRows], null, 'A1', true);
        }

        $path = tempnam(sys_get_temp_dir(), 'dish-import');
        (new Xlsx($book))->save($path);
        $bytes = file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    // A PhpSpreadsheet book is a web of references that only the garbage collector frees; without this the
    // workbooks of a few dozen tests pile up and the whole suite runs out of its 128 MB.
    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();
    }

    // `$extra`: the other fields of the form, like ['mode' => 'update', 'preview' => 1].
    private function importAs($user, ?UploadedFile $file, array $extra = [])
    {
        return $this->actingAs($user)->postJson(route('dishes.import'), ($file ? ['file' => $file] : []) + $extra);
    }

    // The headings of the filled-in template (an ID column first).
    private const HEADINGS_ID = ['ID', 'Dish Name *', 'Dish Types *', 'Price *', 'Description', 'Calories', 'Main Ingredients', 'Comes With Rice', 'Available'];

    // A dish on the menu with its types attached; returns it.
    private function dish(string $name, array $types = [], array $attributes = []): Dish
    {
        $dish = Dish::factory()->create($attributes + ['dish_name' => $name, 'price' => 100]);

        foreach ($types as $type) {
            $dish->dishTypes()->attach($type->dish_type_id);
        }

        return $dish;
    }

    // What types a dish has, as names.
    private function typesOf(Dish $dish): array
    {
        return DB::table('dish_dish_types')
            ->join('dish_types', 'dish_types.dish_type_id', '=', 'dish_dish_types.dish_type_id')
            ->where('dish_dish_types.dish_id', $dish->dish_id)
            ->orderBy('type_name')
            ->pluck('type_name')
            ->all();
    }

    // The template with the current menu, as the PhpSpreadsheet book the person would open.
    private function currentMenuBook($user)
    {
        $response = $this->actingAs($user)->get(route('dishes.import.template', ['current' => 1]));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'dish-menu');
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        @unlink($path);

        return $book;
    }

    private function type(string $name, bool $active = true): DishType
    {
        return DishType::factory()->create(['type_name' => $name, 'is_active' => $active]);
    }

    public function test_guests_and_people_without_create_dishes_are_kept_out(): void
    {
        $this->get(route('dishes.import.template'))->assertRedirect(route('login'));
        $this->postJson(route('dishes.import'))->assertUnauthorized();

        $viewer = $this->userWith('view dishes');

        $this->actingAs($viewer)->get(route('dishes.import.template'))->assertForbidden();
        $this->importAs($viewer, $this->workbook([['Adobo', 'Mains', 100]]))->assertForbidden();
        $this->assertSame(0, DB::table('dishes')->count());
    }

    public function test_the_page_offers_the_import_only_to_people_who_can_create_dishes(): void
    {
        $this->actingAs($this->superAdmin())->get(route('dishes.index'))
            ->assertOk()
            ->assertSee('id="import-dishes"', false)
            ->assertSee(route('dishes.import.template'), false);

        $this->actingAs($this->userWith('view dishes'))->get(route('dishes.index'))
            ->assertOk()
            ->assertDontSee('id="import-dishes"', false);
    }

    public function test_the_template_is_an_excel_file_with_the_headings_and_the_available_types(): void
    {
        $this->type('Mains');
        $this->type('Desserts');
        $this->type('Turned Off', false);

        $response = $this->actingAs($this->userWith('create dishes'))->get(route('dishes.import.template'));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('dish-import-template.xlsx', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'dish-template');
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        @unlink($path);

        // Instructions first, then the first table (dish types), then the second (dishes).
        $this->assertSame(['Instructions', 'Dish Types', 'Dishes'], $book->getSheetNames());
        $this->assertSame('Instructions', $book->getSheet(0)->getTitle());
        $this->assertSame(
            ['Dish Name *', 'Dish Types *', 'Price *', 'Description', 'Calories', 'Main Ingredients', 'Comes With Rice', 'Available'],
            array_slice($book->getSheetByName('Dishes')->toArray()[0], 0, 8),
        );
        $this->assertSame(['Dish Type *', 'Description'], array_slice($book->getSheetByName('Dish Types')->toArray()[0], 0, 2));

        // Everything that can be a dropdown is one: the types (read from the Dish Types sheet, so a type
        // typed there appears in it; it only informs, because a dish may carry several types in one cell)
        // and both Yes / No columns.
        $rules = [];
        foreach (['B', 'G', 'H'] as $letter) {
            $rule = $book->getSheetByName('Dishes')->getCell($letter . '2')->getDataValidation();
            $rules[$letter] = [$rule->getType(), $rule->getFormula1(), $rule->getErrorStyle()];
        }
        $this->assertSame('list', $rules['B'][0]);
        $this->assertSame('information', $rules['B'][2]);
        $this->assertStringContainsString("'Dish Types'!\$A\$2", $rules['B'][1]);
        $this->assertStringContainsString('COUNTA', $rules['B'][1]);
        $this->assertSame(['list', '"Yes,No"', 'stop'], $rules['G']);
        $this->assertSame(['list', '"Yes,No"', 'stop'], $rules['H']);

        $types = array_filter(array_column($book->getSheetByName('Dish Types')->toArray(), 0));
        $this->assertContains('Mains', $types);
        $this->assertContains('Desserts', $types);
        $this->assertNotContains('Turned Off', $types);
    }

    public function test_the_template_comes_back_through_the_importer_as_an_empty_file(): void
    {
        // Nothing but the headings: refused with a clear sentence, not a crash, and nothing stored.
        $template = DishImport::template(DishType::activeOptions());
        $path = tempnam(sys_get_temp_dir(), 'dish-template');
        (new Xlsx($template))->save($path);

        $result = DishImport::read($path);
        @unlink($path);

        $this->assertSame([], $result['rows']);
        $this->assertSame(['The file has no dishes. Fill in the Dishes sheet from row 2.'], $result['errors']);
    }

    public function test_a_file_creates_the_dishes_with_all_their_types_in_one_go(): void
    {
        $mains = $this->type('Mains');
        $chicken = $this->type('Chicken');
        $drinks = $this->type('Drinks');

        $response = $this->importAs($this->superAdmin(), $this->workbook([
            ['Chicken Adobo', 'Mains, chicken', 120.5, 'Braised', 520, 'Chicken, soy', 'Yes', 'Yes'],
            // Only the three required cells: rice defaults to No, availability to Yes, the rest stays empty.
            ['Iced Tea', 'DRINKS', 35],
            ['Sinigang', 'Mains;Chicken', '₱1,200.00', null, null, null, 'no', 'No'],
        ]));

        $response->assertOk()->assertJson(['status' => 'success', 'message' => '3 dishes imported.']);

        $adobo = DB::table('dishes')->where('dish_name', 'Chicken Adobo')->first();
        $this->assertSame('120.50', number_format((float) $adobo->price, 2, '.', ''));
        $this->assertSame('Braised', $adobo->description);
        $this->assertSame(520, (int) $adobo->calories);
        $this->assertSame('Chicken, soy', $adobo->main_ingredients);
        $this->assertSame(1, (int) $adobo->includes_rice);
        $this->assertSame(1, (int) $adobo->is_active);
        $this->assertNull($adobo->image);
        $this->assertEqualsCanonicalizing(
            [$mains->dish_type_id, $chicken->dish_type_id],
            Dish::typesOfDish((int) $adobo->dish_id) ? array_column(Dish::typesOfDish((int) $adobo->dish_id), 'dish_type_id') : [],
        );

        $tea = DB::table('dishes')->where('dish_name', 'Iced Tea')->first();
        $this->assertSame(0, (int) $tea->includes_rice);
        $this->assertSame(1, (int) $tea->is_active);
        $this->assertNull($tea->calories);
        $this->assertSame([$drinks->dish_type_id], array_column(Dish::typesOfDish((int) $tea->dish_id), 'dish_type_id'));

        $sinigang = DB::table('dishes')->where('dish_name', 'Sinigang')->first();
        $this->assertSame(1200.0, (float) $sinigang->price);
        $this->assertSame(0, (int) $sinigang->is_active);
        $this->assertCount(2, Dish::typesOfDish((int) $sinigang->dish_id));

        // The table feed shows them at once (the cached list was dropped by the write).
        $feed = $this->actingAs($this->superAdmin())->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('dishes.index'))->json('data');
        $this->assertCount(3, $feed);
    }

    public function test_one_dish_gives_the_singular_message(): void
    {
        $this->type('Mains');

        $this->importAs($this->superAdmin(), $this->workbook([['Adobo', 'Mains', 100]]))
            ->assertOk()->assertJson(['message' => '1 dish imported.']);
    }

    public function test_it_is_all_or_nothing_and_names_every_bad_row(): void
    {
        $this->type('Mains');

        $response = $this->importAs($this->superAdmin(), $this->workbook([
            ['Good Dish', 'Mains', 50],
            ['Bad Type', 'Nope', 50],
            [null, 'Mains', 50],
            ['Bad Price', 'Mains', 'free'],
            ['Bad Rice', 'Mains', 10, null, null, null, 'maybe'],
            ['Bad Calories', 'Mains', 10, null, 12.5],
            ['No Type', null, 10],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('file');

        $errors = implode("\n", $response->json('errors.file'));
        $this->assertStringContainsString('Row 3: Dish type not available: Nope', $errors);
        $this->assertStringContainsString('Row 4:', $errors);
        $this->assertStringContainsString('Row 5: The Price field must be a number', $errors);
        $this->assertStringContainsString('Row 6: Comes With Rice must be Yes or No.', $errors);
        $this->assertStringContainsString('Row 7:', $errors);
        $this->assertStringContainsString('Row 8: Dish Types is required', $errors);
        $this->assertStringNotContainsString('Row 2:', $errors);

        // The good row on top was NOT imported either.
        $this->assertSame(0, DB::table('dishes')->count());
        $this->assertSame(0, DB::table('dish_dish_types')->count());
    }

    public function test_a_type_that_is_turned_off_or_deleted_is_refused(): void
    {
        $this->type('Off', false);
        $gone = $this->type('Gone');
        $gone->delete();

        $response = $this->importAs($this->superAdmin(), $this->workbook([['A', 'Off', 1], ['B', 'Gone', 1]]));

        $response->assertStatus(422);
        $this->assertStringContainsString('Dish type not available: Off', implode("\n", $response->json('errors.file')));
        $this->assertStringContainsString('Dish type not available: Gone', implode("\n", $response->json('errors.file')));
        $this->assertSame(0, DB::table('dishes')->count());
    }

    public function test_a_type_typed_in_the_dish_types_table_is_created_and_used_by_the_dishes(): void
    {
        $existing = $this->type('Mains');

        $response = $this->importAs($this->superAdmin(), $this->workbook(
            [
                ['Halo-Halo', 'Desserts', 80],
                ['Leche Flan', 'desserts, Snacks', 60],
                ['Adobo', 'Mains, Snacks', 100],
            ],
            null,
            'dishes.xlsx',
            [
                ['Mains', null],                          // already exists: listed so the dropdown shows it, not duplicated
                ['Desserts', 'Sweet things'],             // new
                ['  snacks ', null],                      // new (spaces and case do not matter)
                ['DESSERTS', 'a repeat is ignored'],      // the same name again
                [null, null],                             // blank rows are spacing
            ],
        ));

        $response->assertOk()->assertJson(['message' => '3 dishes imported. 2 new dish types added.']);

        $this->assertSame(1, DB::table('dish_types')->where('type_name', 'Mains')->count());
        $desserts = DB::table('dish_types')->where('type_name', 'Desserts')->first();
        $this->assertSame('Sweet things', $desserts->description);
        $this->assertSame(1, (int) $desserts->is_active);
        $this->assertNull($desserts->deleted_at);
        $snacks = DB::table('dish_types')->where('type_name', 'snacks')->first();
        $this->assertNotNull($snacks);

        $typesOf = fn (string $dish) => collect(Dish::typesOfDish((int) DB::table('dishes')->where('dish_name', $dish)->value('dish_id')))
            ->pluck('dish_type_id')->sort()->values()->all();

        $this->assertSame([$desserts->dish_type_id], $typesOf('Halo-Halo'));
        $this->assertSame(collect([$desserts->dish_type_id, $snacks->dish_type_id])->sort()->values()->all(), $typesOf('Leche Flan'));
        $this->assertSame(collect([$existing->dish_type_id, $snacks->dish_type_id])->sort()->values()->all(), $typesOf('Adobo'));
    }

    public function test_a_dish_cannot_use_a_type_that_is_not_in_the_table_nor_on_the_menu(): void
    {
        $this->type('Mains');

        $response = $this->importAs($this->superAdmin(), $this->workbook(
            [['Halo-Halo', 'Desserts', 80]],
            null,
            'dishes.xlsx',
            [['Snacks', null]],
        ));

        $response->assertStatus(422);
        $this->assertStringContainsString('Row 2: Dish type not available: Desserts', $response->json('errors.file.0'));
        $this->assertStringContainsString('add it to the Dish Types sheet', $response->json('errors.file.0'));
        // Snacks was fine on its own, but the file is refused as a whole.
        $this->assertSame(0, DB::table('dish_types')->where('type_name', 'Snacks')->count());
    }

    public function test_a_bad_dish_row_creates_no_new_type_either(): void
    {
        $this->importAs($this->superAdmin(), $this->workbook(
            [['Halo-Halo', 'Desserts', 'free']],
            null,
            'dishes.xlsx',
            [['Desserts', null]],
        ))->assertStatus(422);

        $this->assertSame(0, DB::table('dish_types')->count());
        $this->assertSame(0, DB::table('dishes')->count());
    }

    public function test_adding_new_types_needs_the_permission_to_create_dish_types(): void
    {
        $this->type('Mains');
        $importer = $this->userWith('create dishes');

        // New types in the file: refused, nothing stored.
        $response = $this->importAs($importer, $this->workbook(
            [['Halo-Halo', 'Desserts', 80]],
            null,
            'dishes.xlsx',
            [['Desserts', null]],
        ));

        $response->assertStatus(422);
        $this->assertStringContainsString('Dish Types sheet, row 2', $response->json('errors.file.0'));
        $this->assertStringContainsString('create dish-types', $response->json('errors.file.0'));
        $this->assertSame(0, DB::table('dishes')->count());
        $this->assertSame(0, DB::table('dish_types')->where('type_name', 'Desserts')->count());

        // The same person can still import dishes of the types that already exist (even listed on the sheet).
        $this->importAs($importer, $this->workbook(
            [['Adobo', 'Mains', 100]],
            null,
            'dishes.xlsx',
            [['Mains', null]],
        ))->assertOk()->assertJson(['message' => '1 dish imported.']);

        // And with that permission as well, the new type is made.
        $this->importAs($this->userWith('create dishes', 'create dish-types'), $this->workbook(
            [['Halo-Halo', 'Desserts', 80]],
            null,
            'dishes.xlsx',
            [['Desserts', null]],
        ))->assertOk()->assertJson(['message' => '1 dish imported. 1 new dish type added.']);
    }

    public function test_the_dish_types_table_is_checked_row_by_row(): void
    {
        $this->type('Off', false);
        $gone = $this->type('Gone');
        $gone->delete();

        $response = $this->importAs($this->superAdmin(), $this->workbook(
            [['Adobo', 'Mains', 100]],
            null,
            'dishes.xlsx',
            [
                ['Off', null],
                ['Gone', null],
                ['=1+1', null],
                [null, 'a description without a name'],
                [str_repeat('x', 256), null],
            ],
        ));

        $response->assertStatus(422);
        $errors = implode("\n", $response->json('errors.file'));
        $this->assertStringContainsString('Dish Types sheet, row 2: "Off" is turned off', $errors);
        $this->assertStringContainsString('Dish Types sheet, row 3: "Gone" was a dish type that has been deleted', $errors);
        $this->assertStringContainsString('Dish Types sheet, row 4: a cell holds a formula', $errors);
        $this->assertStringContainsString('Dish Types sheet, row 5: the dish type needs a name', $errors);
        $this->assertStringContainsString('Dish Types sheet, row 6: the name may have 255', $errors);
    }

    public function test_a_file_may_add_only_types(): void
    {
        $this->importAs($this->superAdmin(), $this->workbook([], null, 'dishes.xlsx', [['Desserts', null]]))
            ->assertOk()
            ->assertJson(['message' => '0 dishes imported. 1 new dish type added.']);

        $this->assertSame(1, DB::table('dish_types')->where('type_name', 'Desserts')->count());
    }

    public function test_a_file_without_a_dishes_sheet_is_refused(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Sheet1')->fromArray([self::HEADINGS, ['Adobo', 'Mains', 100]], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'dish-import');
        (new Xlsx($book))->save($path);
        $bytes = file_get_contents($path);
        @unlink($path);

        $response = $this->importAs($this->superAdmin(), UploadedFile::fake()->createWithContent('dishes.xlsx', $bytes));

        $response->assertStatus(422);
        $this->assertStringContainsString('no "Dishes" sheet', $response->json('errors.file.0'));
    }

    public function test_a_dish_repeated_inside_the_file_is_refused_and_a_deleted_name_is_free(): void
    {
        $this->type('Mains');
        $deleted = Dish::factory()->create(['dish_name' => 'Old Dish']);
        $deleted->delete();

        $response = $this->importAs($this->superAdmin(), $this->workbook([
            ['Old Dish', 'Mains', 1],   // a deleted dish does not block its name
            ['Twin', 'Mains', 1],
            ['TWIN', 'Mains', 1],       // repeated inside the file, case ignored
        ]));

        $response->assertStatus(422);
        $errors = implode("\n", $response->json('errors.file'));
        $this->assertStringNotContainsString('Row 2:', $errors);
        $this->assertStringContainsString('Row 3: a dish named "Twin" would exist twice', $errors);
        $this->assertStringContainsString('Row 4: a dish named "TWIN" would exist twice', $errors);
        $this->assertSame(0, DB::table('dishes')->whereNull('deleted_at')->count());
    }

    public function test_adding_skips_a_dish_that_is_already_on_the_menu_and_lists_it(): void
    {
        $this->type('Mains');
        Dish::factory()->create(['dish_name' => 'Adobo', 'price' => 50]);

        $response = $this->importAs($this->superAdmin(), $this->workbook([
            ['  adobo ', 'Mains', 999],   // same name, case and spaces ignored: left alone
            ['Sinigang', 'Mains', 110],
        ]));

        $response->assertOk();
        $this->assertStringContainsString('1 dish imported.', $response->json('message'));
        $this->assertStringContainsString('1 dish skipped because already on the menu (Adobo)', $response->json('message'));
        $this->assertSame(2, DB::table('dishes')->count());
        $this->assertEquals(50, DB::table('dishes')->where('dish_name', 'Adobo')->value('price'));
    }

    public function test_importing_the_same_file_twice_does_not_duplicate_the_menu(): void
    {
        $this->type('Mains');
        $user = $this->superAdmin();
        $rows = [['Adobo', 'Mains', 100], ['Sinigang', 'Mains', 110]];

        $this->importAs($user, $this->workbook($rows))->assertOk();

        $again = $this->importAs($user, $this->workbook($rows));
        $again->assertOk();
        $this->assertStringContainsString('0 dishes imported. 2 dishes skipped', $again->json('message'));

        $this->assertSame(2, DB::table('dishes')->count());
    }

    public function test_column_order_does_not_matter_blank_rows_are_skipped_and_headings_are_forgiving(): void
    {
        $mains = $this->type('Mains');

        $this->importAs($this->superAdmin(), $this->workbook(
            [
                [95, 'mains', 'Beef Tapa'],
                [null, null, null],
                [80, 'Mains', 'Longganisa'],
            ],
            ['price', 'Dish Type', 'name'],
        ))->assertOk()->assertJson(['message' => '2 dishes imported.']);

        $this->assertSame(95.0, (float) DB::table('dishes')->where('dish_name', 'Beef Tapa')->value('price'));
        $this->assertSame(2, DB::table('dish_dish_types')->where('dish_type_id', $mains->dish_type_id)->count());
    }

    public function test_a_missing_required_column_asks_for_the_template(): void
    {
        $this->type('Mains');

        $response = $this->importAs($this->superAdmin(), $this->workbook([['Adobo', 100]], ['Dish Name', 'Price']));

        $response->assertStatus(422);
        $this->assertStringContainsString('Column Dish Types is missing', $response->json('errors.file.0'));
        $this->assertSame(0, DB::table('dishes')->count());
    }

    public function test_a_formula_is_refused_and_never_calculated(): void
    {
        $this->type('Mains');

        $response = $this->importAs($this->superAdmin(), $this->workbook([['Adobo', 'Mains', '=1+1']]));

        $response->assertStatus(422);
        $this->assertStringContainsString('Price holds a formula', $response->json('errors.file.0'));
        $this->assertSame(0, DB::table('dishes')->count());
    }

    public function test_something_that_is_not_a_workbook_is_refused_in_plain_words(): void
    {
        $fake = UploadedFile::fake()->createWithContent('dishes.xlsx', "Dish Name,Dish Types,Price\nAdobo,Mains,100\n");

        $this->importAs($this->superAdmin(), $fake)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, DB::table('dishes')->count());
    }

    public function test_the_extension_and_the_size_are_checked(): void
    {
        $user = $this->superAdmin();

        $this->importAs($user, null)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->importAs($user, UploadedFile::fake()->createWithContent('dishes.csv', 'a,b'))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->importAs($user, UploadedFile::fake()->create('big.xlsx', 3000))->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_too_many_rows_are_refused(): void
    {
        $this->type('Mains');

        // A sparse sheet: only the last allowed row + 1 is filled, which is all the limit looks at (building
        // thousands of real rows would eat the test run's memory for nothing).
        $rows = array_fill(0, DishImport::MAX_ROWS, [null, null, null]);
        $rows[] = ['Dish', 'Mains', 10];

        $response = $this->importAs($this->superAdmin(), $this->workbook($rows));

        $response->assertStatus(422);
        $this->assertStringContainsString('more than ' . DishImport::MAX_ROWS . ' dishes', $response->json('errors.file.0'));
        $this->assertSame(0, DB::table('dishes')->count());
    }

    public function test_too_many_dish_types_are_refused(): void
    {
        $types = [];
        for ($i = 1; $i <= DishImport::MAX_TYPES + 1; $i++) {
            $types[] = ['Type ' . $i, null];
        }

        $response = $this->importAs($this->superAdmin(), $this->workbook([['Adobo', 'Type 1', 10]], null, 'dishes.xlsx', $types));

        $response->assertStatus(422);
        $this->assertStringContainsString('more than ' . DishImport::MAX_TYPES . ' dish types', $response->json('errors.file.0'));
        $this->assertSame(0, DB::table('dish_types')->count());
    }
    public function test_a_long_list_of_problems_is_cut_short(): void
    {
        $rows = [];
        for ($i = 1; $i <= DishImport::MAX_REPORTED + 5; $i++) {
            $rows[] = ['Dish ' . $i, 'Nope', 10];
        }

        $errors = $this->importAs($this->superAdmin(), $this->workbook($rows))->assertStatus(422)->json('errors.file');

        $this->assertCount(DishImport::MAX_REPORTED + 1, $errors);
        $this->assertSame('and 5 more rows with problems.', end($errors));
    }

    public function test_a_big_file_is_stored_with_a_handful_of_statements_not_one_per_row(): void
    {
        $this->type('Mains');
        $this->type('Sides');

        $rows = [];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = ['Dish ' . $i, 'Mains, Sides', 10 + $i];
        }

        $file = $this->workbook($rows);
        $user = $this->superAdmin();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->importAs($user, $file)->assertOk();
        $writes = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'insert'))->count();
        DB::disableQueryLog();

        $this->assertSame(120, DB::table('dishes')->count());
        $this->assertSame(240, DB::table('dish_dish_types')->count());
        // 2 dish inserts (100 + 20) and 1 pivot insert, plus the audit row: never 120+.
        $this->assertLessThan(10, $writes);
    }

    /* ---- the template with the current menu, and the update mode ---- */

    // A book (a downloaded template, edited) as an upload.
    private function upload(Spreadsheet $book, string $name = 'menu.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'dish-upload');
        (new Xlsx($book))->save($path);
        $bytes = file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    public function test_the_template_can_come_filled_with_the_current_menu(): void
    {
        $mains = $this->type('Mains');
        $chicken = $this->type('Chicken');
        $adobo = $this->dish('Chicken Adobo', [$mains, $chicken], ['price' => 120.5, 'calories' => 520, 'includes_rice' => true, 'is_active' => false, 'description' => 'Braised']);
        $this->dish('=1+1', [$mains]);                      // text that looks like a formula must stay text
        $this->dish('Gone')->delete();

        $book = $this->currentMenuBook($this->superAdmin());
        $sheet = $book->getSheetByName('Dishes');
        $rows = $sheet->toArray();

        $this->assertSame(self::HEADINGS_ID, array_slice($rows[0], 0, 9));

        $byName = collect(array_slice($rows, 1))->filter(fn ($row) => $row[1] !== null)->keyBy(1);
        $this->assertCount(2, $byName);                     // the deleted dish is not there
        $this->assertEquals($adobo->dish_id, $byName['Chicken Adobo'][0]);
        $this->assertSame('Chicken, Mains', $byName['Chicken Adobo'][2]);
        $this->assertEquals(120.5, $byName['Chicken Adobo'][3]);
        $this->assertSame('Braised', $byName['Chicken Adobo'][4]);
        $this->assertEquals(520, $byName['Chicken Adobo'][5]);
        $this->assertSame('Yes', $byName['Chicken Adobo'][7]);
        $this->assertSame('No', $byName['Chicken Adobo'][8]);

        // A name that starts with "=" is written as text, never as a formula the spreadsheet would run.
        $row = collect(range(2, 3))->first(fn ($number) => $sheet->getCell('B' . $number)->getValue() === '=1+1');
        $this->assertSame('s', $sheet->getCell('B' . $row)->getDataType());

        // The dropdowns now sit one column further right and cover the whole range.
        $types = $sheet->getCell('C2')->getDataValidation();
        $this->assertSame('list', $types->getType());
        $this->assertStringContainsString("'Dish Types'!\$A\$2", $types->getFormula1());
        $this->assertSame('list', $sheet->getCell('H2')->getDataValidation()->getType());
        $this->assertSame('list', $sheet->getCell('I' . (DishImport::MAX_ROWS + 1))->getDataValidation()->getType());
    }

    public function test_the_current_menu_needs_permission_to_view_dishes_the_blank_one_does_not(): void
    {
        $creator = $this->userWith('create dishes');

        $this->actingAs($creator)->get(route('dishes.import.template', ['current' => 1]))->assertForbidden();
        $this->actingAs($creator)->get(route('dishes.import.template'))->assertOk();
        $this->actingAs($this->userWith('create dishes', 'view dishes'))->get(route('dishes.import.template', ['current' => 1]))->assertOk();
    }

    public function test_a_downloaded_menu_sent_back_unchanged_changes_nothing(): void
    {
        $mains = $this->type('Mains');
        $sides = $this->type('Sides');
        $this->dish('Chicken Adobo', [$mains, $sides], ['price' => 120.5, 'calories' => 520, 'includes_rice' => true, 'description' => 'Braised']);
        $this->dish('Iced Tea', [$sides], ['price' => 35, 'calories' => null, 'description' => null, 'main_ingredients' => null]);
        DB::table('dishes')->update(['updated_at' => '2020-01-01 00:00:00']);

        $user = $this->superAdmin();
        $file = $this->upload($this->currentMenuBook($user));

        $preview = $this->importAs($user, $file, ['mode' => 'update', 'preview' => 1]);
        $preview->assertOk();
        $this->assertSame('Nothing to import. 2 dishes unchanged.', $preview->json('message'));

        $this->importAs($user, $this->upload($this->currentMenuBook($user)), ['mode' => 'update'])->assertOk();

        $this->assertSame(2, DB::table('dishes')->count());
        $this->assertSame(0, DB::table('dishes')->where('updated_at', '>', '2020-01-02')->count()); // nothing was written
        $this->assertSame(3, DB::table('dish_dish_types')->count());
    }

    public function test_the_update_mode_changes_dishes_by_id_adds_new_ones_and_leaves_the_rest(): void
    {
        $mains = $this->type('Mains');
        $drinks = $this->type('Drinks');
        $adobo = $this->dish('Adobo', [$mains], ['price' => 100, 'image' => 'dishes/adobo.jpg']);
        $tea = $this->dish('Iced Tea', [$drinks], ['price' => 35]);
        $kept = $this->dish('Kept', [$mains], ['price' => 10]);          // not in the file: untouched

        $file = $this->workbook([
            [$adobo->dish_id, 'Adobo Special', 'Mains, Drinks', '150.00', 'New text', 600, 'Pork', 'Yes', 'No'],
            [null, 'iced tea', 'Drinks', 40],                             // no ID: matched by name (case ignored)
            [null, 'Halo-Halo', 'Drinks', 60],                            // a new dish
        ], self::HEADINGS_ID);

        $response = $this->importAs($this->superAdmin(), $file, ['mode' => 'update']);

        $response->assertOk();
        $this->assertSame('1 dish imported. 2 dishes updated.', $response->json('message'));

        $adobo = DB::table('dishes')->where('dish_id', $adobo->dish_id)->first();
        $this->assertSame('Adobo Special', $adobo->dish_name);
        $this->assertEquals(150, $adobo->price);
        $this->assertSame('New text', $adobo->description);
        $this->assertEquals(600, $adobo->calories);
        $this->assertSame('Pork', $adobo->main_ingredients);
        $this->assertEquals(1, $adobo->includes_rice);
        $this->assertEquals(0, $adobo->is_active);
        $this->assertSame('dishes/adobo.jpg', $adobo->image);            // the picture is never touched
        $this->assertSame(['Drinks', 'Mains'], $this->typesOf(Dish::find($adobo->dish_id)));

        $this->assertEquals(40, DB::table('dishes')->where('dish_id', $tea->dish_id)->value('price'));
        $this->assertSame('iced tea', DB::table('dishes')->where('dish_id', $tea->dish_id)->value('dish_name')); // the file's spelling wins
        $this->assertSame(['Drinks'], $this->typesOf($tea));

        $this->assertEquals(10, DB::table('dishes')->where('dish_id', $kept->dish_id)->value('price'));
        $this->assertSame(['Mains'], $this->typesOf($kept));
        $this->assertSame(4, DB::table('dishes')->count());
        $this->assertSame(1, DB::table('dishes')->where('dish_name', 'Halo-Halo')->count());
    }

    public function test_the_add_mode_never_changes_a_dish_even_when_the_file_carries_its_id(): void
    {
        $mains = $this->type('Mains');
        $adobo = $this->dish('Adobo', [$mains], ['price' => 100]);

        $response = $this->importAs($this->superAdmin(), $this->workbook([
            [$adobo->dish_id, 'Adobo Renamed', 'Mains', 999],
        ], self::HEADINGS_ID));

        $response->assertOk();
        $this->assertStringContainsString('1 dish skipped', $response->json('message'));
        $this->assertSame('Adobo', DB::table('dishes')->where('dish_id', $adobo->dish_id)->value('dish_name'));
        $this->assertEquals(100, DB::table('dishes')->where('dish_id', $adobo->dish_id)->value('price'));
    }

    public function test_updating_names_is_judged_on_the_names_the_menu_will_have(): void
    {
        $mains = $this->type('Mains');
        $a = $this->dish('A', [$mains]);
        $b = $this->dish('B', [$mains]);

        // A name another row gives up in the same file is free (A takes B's name while B moves on to C).
        $this->importAs($this->superAdmin(), $this->workbook([
            [$a->dish_id, 'B', 'Mains', 1],
            [$b->dish_id, 'C', 'Mains', 1],
        ], self::HEADINGS_ID), ['mode' => 'update'])->assertOk();

        $this->assertSame('B', DB::table('dishes')->where('dish_id', $a->dish_id)->value('dish_name'));
        $this->assertSame('C', DB::table('dishes')->where('dish_id', $b->dish_id)->value('dish_name'));

        // A real swap works too.
        $this->importAs($this->superAdmin(), $this->workbook([
            [$a->dish_id, 'C', 'Mains', 1],
            [$b->dish_id, 'B', 'Mains', 1],
        ], self::HEADINGS_ID), ['mode' => 'update'])->assertOk();

        $this->assertSame('C', DB::table('dishes')->where('dish_id', $a->dish_id)->value('dish_name'));
        $this->assertSame('B', DB::table('dishes')->where('dish_id', $b->dish_id)->value('dish_name'));

        // Taking the name of a dish that stays is refused, and nothing at all is stored.
        $other = $this->dish('Other', [$mains], ['price' => 5]);
        $response = $this->importAs($this->superAdmin(), $this->workbook([
            [$other->dish_id, 'C', 'Mains', 99],    // "C" belongs to a dish that is not in the file
        ], self::HEADINGS_ID), ['mode' => 'update']);

        $response->assertStatus(422);
        $this->assertStringContainsString('Row 2: a dish named "C" would exist twice', $response->json('errors.file.0'));
        $this->assertSame('Other', DB::table('dishes')->where('dish_id', $other->dish_id)->value('dish_name'));
    }

    public function test_a_row_that_names_a_dish_that_is_gone_or_twice_is_a_row_error(): void
    {
        $mains = $this->type('Mains');
        $live = $this->dish('Live', [$mains]);
        $deleted = $this->dish('Deleted', [$mains]);
        $deleted->delete();

        $response = $this->importAs($this->superAdmin(), $this->workbook([
            [$deleted->dish_id, 'Deleted', 'Mains', 1],
            [9999, 'Nobody', 'Mains', 1],
            ['abc', 'Odd', 'Mains', 1],
            [$live->dish_id, 'Live', 'Mains', 1],
            [$live->dish_id, 'Live Again', 'Mains', 1],
        ], self::HEADINGS_ID), ['mode' => 'update']);

        $response->assertStatus(422);
        $errors = $response->json('errors.file');
        $this->assertStringContainsString('Row 2: ID ' . $deleted->dish_id . ' is not a dish on the menu', $errors[0]);
        $this->assertStringContainsString('Row 3: ID 9999 is not a dish on the menu', $errors[1]);
        $this->assertStringContainsString('Row 4: ID must be the number', $errors[2]);
        $this->assertStringContainsString('Row 6: This dish is already on row 5.', $errors[3]);
        $this->assertSame(1, DB::table('dishes')->whereNull('deleted_at')->count());
    }

    public function test_one_bad_row_updates_nothing(): void
    {
        $mains = $this->type('Mains');
        $a = $this->dish('A', [$mains], ['price' => 10]);
        $b = $this->dish('B', [$mains], ['price' => 20]);

        $response = $this->importAs($this->superAdmin(), $this->workbook([
            [$a->dish_id, 'A', 'Mains', 11],
            [$b->dish_id, 'B', 'Nowhere', 21],      // unknown type
        ], self::HEADINGS_ID), ['mode' => 'update']);

        $response->assertStatus(422);
        $this->assertEquals(10, DB::table('dishes')->where('dish_id', $a->dish_id)->value('price'));
        $this->assertEquals(20, DB::table('dishes')->where('dish_id', $b->dish_id)->value('price'));
    }

    public function test_a_dish_keeps_a_type_that_was_turned_off_since_but_cannot_gain_one(): void
    {
        $off = $this->type('Seasonal', false);
        $mains = $this->type('Mains');
        $other = $this->type('Retired', false);
        $dish = $this->dish('Halo-Halo', [$off, $mains], ['price' => 50]);

        // The turned-off type it already has is fine: the dish can be re-imported with a new price.
        $this->importAs($this->superAdmin(), $this->workbook([
            [$dish->dish_id, 'Halo-Halo', 'Mains, Seasonal', 55],
        ], self::HEADINGS_ID), ['mode' => 'update'])->assertOk();

        $this->assertEquals(55, DB::table('dishes')->where('dish_id', $dish->dish_id)->value('price'));
        $this->assertSame(['Mains', 'Seasonal'], $this->typesOf($dish));

        // A turned-off type it does not have is still refused.
        $this->importAs($this->superAdmin(), $this->workbook([
            [$dish->dish_id, 'Halo-Halo', 'Mains, Retired', 55],
        ], self::HEADINGS_ID), ['mode' => 'update'])->assertStatus(422);
    }

    public function test_the_update_mode_needs_the_permission_to_edit_dishes(): void
    {
        $mains = $this->type('Mains');
        $dish = $this->dish('Adobo', [$mains], ['price' => 100]);
        $file = fn () => $this->workbook([[$dish->dish_id, 'Adobo', 'Mains', 1]], self::HEADINGS_ID);

        $creator = $this->userWith('create dishes');

        $this->importAs($creator, $file(), ['mode' => 'update'])->assertForbidden();
        $this->importAs($creator, $file(), ['mode' => 'update', 'preview' => 1])->assertForbidden();
        $this->importAs($creator, $file(), ['mode' => 'add'])->assertOk();         // adding still works
        $this->importAs($this->userWith('create dishes', 'edit dishes'), $file(), ['mode' => 'update'])->assertOk();
        $this->importAs($this->superAdmin(), $file(), ['mode' => 'sideways'])->assertStatus(422);

        $this->assertEquals(1, DB::table('dishes')->where('dish_id', $dish->dish_id)->value('price'));
    }

    public function test_a_check_says_what_would_happen_and_stores_nothing(): void
    {
        $mains = $this->type('Mains');
        $adobo = $this->dish('Adobo', [$mains], ['price' => 100]);
        $this->dish('Tea', [$mains], ['price' => 5, 'description' => null, 'calories' => null, 'main_ingredients' => null]);

        $file = fn () => $this->workbook([
            [$adobo->dish_id, 'Adobo', 'Mains', 120],
            [null, 'Tea', 'Mains', 5],
            [null, 'Cake', 'Mains', 80],
        ], self::HEADINGS_ID);

        $check = $this->importAs($this->superAdmin(), $file(), ['mode' => 'update', 'preview' => 1]);

        $check->assertOk();
        $this->assertSame('1 dish to add. 1 dish to update. 1 dish unchanged.', $check->json('message'));
        $this->assertSame(2, DB::table('dishes')->count());
        $this->assertEquals(100, DB::table('dishes')->where('dish_id', $adobo->dish_id)->value('price'));

        // The same file, confirmed (preview = 0), is saved.
        $this->importAs($this->superAdmin(), $file(), ['mode' => 'update', 'preview' => 0])->assertOk();
        $this->assertSame(3, DB::table('dishes')->count());
        $this->assertEquals(120, DB::table('dishes')->where('dish_id', $adobo->dish_id)->value('price'));

        // A check of a bad file is refused like the import itself.
        $this->importAs($this->superAdmin(), $this->workbook([['', 'Mains', 1]]), ['preview' => 1])->assertStatus(422);
    }

    public function test_a_check_reports_new_dish_types_too(): void
    {
        $this->type('Mains');

        $check = $this->importAs($this->superAdmin(), $this->workbook(
            [['Adobo', 'Mains, Brunch', 100]],
            null,
            'dishes.xlsx',
            [['Brunch', null]],
        ), ['preview' => 1]);

        $check->assertOk();
        $this->assertSame('1 dish to add. 1 new dish type to add.', $check->json('message'));
        $this->assertSame(0, DB::table('dishes')->count());
        $this->assertSame(1, DB::table('dish_types')->count());
    }

    public function test_updating_many_dishes_takes_a_handful_of_statements_not_one_per_dish(): void
    {
        $mains = $this->type('Mains');
        $sides = $this->type('Sides');

        $rows = [];
        for ($i = 1; $i <= 120; $i++) {
            $dish = $this->dish('Dish ' . $i, [$mains], ['price' => 10]);
            $rows[] = [$dish->dish_id, 'Dish ' . $i, 'Mains, Sides', 20 + $i];
        }

        $file = $this->workbook($rows, self::HEADINGS_ID);
        $user = $this->superAdmin();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->importAs($user, $file, ['mode' => 'update'])->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->map('strtolower');
        DB::disableQueryLog();

        $this->assertEquals(21, DB::table('dishes')->where('dish_name', 'Dish 1')->value('price'));
        $this->assertSame(240, DB::table('dish_dish_types')->count());
        $this->assertSame(0, $queries->filter(fn ($sql) => str_starts_with($sql, 'update "dishes"') || str_starts_with($sql, 'update `dishes`'))->count());
        // Everything the import itself ran (reads, 2 dish upserts, 1 delete, 1 pivot insert, the audit row) stays small.
        $this->assertLessThan(30, $queries->count());
    }

    public function test_a_dish_deleted_between_the_check_and_the_save_stops_the_whole_import(): void
    {
        $mains = $this->type('Mains');
        $a = $this->dish('A', [$mains], ['price' => 10]);
        $b = $this->dish('B', [$mains], ['price' => 20]);

        $result = DishImport::judge(
            [self::HEADINGS_ID, [$a->dish_id, 'A', 'Mains', 11], [$b->dish_id, 'B', 'Mains', 21]],
            [],
            true,
            DishImport::MODE_UPDATE,
        );
        $this->assertSame([], $result['errors']);

        $b->delete();   // someone deletes it after the file was checked

        $this->expectException(\App\Exceptions\DishGoneException::class);

        try {
            DishImport::store($result);
        } finally {
            $this->assertEquals(10, DB::table('dishes')->where('dish_id', $a->dish_id)->value('price')); // rolled back
        }
    }

    public function test_a_column_the_file_does_not_have_is_left_alone_but_an_empty_cell_clears_it(): void
    {
        $mains = $this->type('Mains');
        $dish = $this->dish('Adobo', [$mains], ['price' => 100, 'description' => 'Braised', 'calories' => 500, 'main_ingredients' => 'Pork', 'includes_rice' => true, 'is_active' => false]);
        $user = $this->superAdmin();

        // A short file (only the three required columns) changes the price and nothing else.
        $this->importAs($user, $this->workbook([['Adobo', 'Mains', 130]], ['Dish Name *', 'Dish Types *', 'Price *']), ['mode' => 'update'])->assertOk();

        $row = DB::table('dishes')->where('dish_id', $dish->dish_id)->first();
        $this->assertEquals(130, $row->price);
        $this->assertSame('Braised', $row->description);
        $this->assertEquals(500, $row->calories);
        $this->assertSame('Pork', $row->main_ingredients);
        $this->assertEquals(1, $row->includes_rice);
        $this->assertEquals(0, $row->is_active);

        // With the columns in the file, an empty cell is what the person sees: it clears the value
        // (and an empty Yes / No is back to its default: no rice, available).
        $this->importAs($user, $this->workbook([['Adobo', 'Mains', 130, null, null, null, null, null]]), ['mode' => 'update'])->assertOk();

        $row = DB::table('dishes')->where('dish_id', $dish->dish_id)->first();
        $this->assertNull($row->description);
        $this->assertNull($row->calories);
        $this->assertNull($row->main_ingredients);
        $this->assertEquals(0, $row->includes_rice);
        $this->assertEquals(1, $row->is_active);
    }

    public function test_a_filled_in_template_without_an_id_column_still_works(): void
    {
        $mains = $this->type('Mains');
        $this->dish('Adobo', [$mains], ['price' => 100]);

        // The older (blank) template has no ID column: rows are matched by name.
        $this->importAs($this->superAdmin(), $this->workbook([['Adobo', 'Mains', 130]]), ['mode' => 'update'])->assertOk();

        $this->assertEquals(130, DB::table('dishes')->where('dish_name', 'Adobo')->value('price'));
    }

    /* ---- replace the entire menu ---- */

    public function test_replacing_the_menu_with_a_blank_template_file_keeps_matching_dishes_and_removes_the_rest(): void
    {
        $mains = $this->type('Mains');
        $adobo = $this->dish('Adobo', [$mains], ['price' => 100, 'image' => 'dishes/adobo.jpg']);
        $gone = $this->dish('Old Special', [$mains], ['image' => 'dishes/old.jpg']);
        $also = $this->dish('Also Gone', [$mains]);

        // No ID column (the blank template): "Adobo" is matched by name, so it keeps its id.
        $response = $this->importAs($this->superAdmin(), $this->workbook([
            ['adobo', 'Mains', 130],
            ['Sinigang', 'Mains', 110],
        ]), ['mode' => 'replace']);

        $response->assertOk();
        $this->assertSame('1 dish imported. 1 dish updated. 2 dishes removed.', $response->json('message'));

        // The dish that was in the file is the same row (its sessions and orders stay linked).
        $this->assertEquals(130, DB::table('dishes')->where('dish_id', $adobo->dish_id)->value('price'));
        $this->assertNull(DB::table('dishes')->where('dish_id', $adobo->dish_id)->value('deleted_at'));

        // The ones the file left out are soft deleted; their rows and pictures are kept.
        foreach ([$gone, $also] as $dish) {
            $this->assertNotNull(DB::table('dishes')->where('dish_id', $dish->dish_id)->value('deleted_at'));
        }
        $this->assertSame('dishes/old.jpg', DB::table('dishes')->where('dish_id', $gone->dish_id)->value('image'));

        $live = DB::table('dishes')->whereNull('deleted_at')->pluck('dish_name')->all();
        sort($live);
        $this->assertSame(['Sinigang', 'adobo'], $live);
    }

    public function test_a_check_of_a_replace_says_what_would_be_removed_and_removes_nothing(): void
    {
        $mains = $this->type('Mains');
        $this->dish('Adobo', [$mains]);
        $this->dish('Old Special', [$mains]);

        $check = $this->importAs($this->superAdmin(), $this->workbook([['Adobo', 'Mains', 100]]), ['mode' => 'replace', 'preview' => 1]);

        $check->assertOk();
        $this->assertStringContainsString('1 dish to REMOVE from the menu (Old Special)', $check->json('message'));
        $this->assertSame(2, DB::table('dishes')->whereNull('deleted_at')->count());
    }

    public function test_a_downloaded_menu_sent_back_unchanged_in_replace_mode_removes_nothing(): void
    {
        $mains = $this->type('Mains');
        $this->dish('Adobo', [$mains]);
        $this->dish('Tea', [$mains]);
        $user = $this->superAdmin();

        $check = $this->importAs($user, $this->upload($this->currentMenuBook($user)), ['mode' => 'replace', 'preview' => 1]);

        $this->assertSame('Nothing to import. 2 dishes unchanged.', $check->json('message'));
    }

    public function test_deleting_rows_from_a_downloaded_menu_removes_those_dishes(): void
    {
        $mains = $this->type('Mains');
        $this->dish('Adobo', [$mains]);
        $this->dish('Tea', [$mains]);
        $user = $this->superAdmin();

        $book = $this->currentMenuBook($user);
        $sheet = $book->getSheetByName('Dishes');
        $row = collect([2, 3])->first(fn ($number) => $sheet->getCell('B' . $number)->getValue() === 'Tea');
        $sheet->removeRow($row);

        $this->importAs($user, $this->upload($book), ['mode' => 'replace'])->assertOk();

        $this->assertSame(['Adobo'], DB::table('dishes')->whereNull('deleted_at')->pluck('dish_name')->all());
    }

    public function test_a_replace_that_carries_no_dish_is_refused_so_the_menu_cannot_be_emptied(): void
    {
        $mains = $this->type('Mains');
        $this->dish('Adobo', [$mains]);

        // Only the headings, and a file with only a new type: both would empty the menu.
        $this->importAs($this->superAdmin(), $this->workbook([]), ['mode' => 'replace'])->assertStatus(422);

        $typesOnly = $this->importAs($this->superAdmin(), $this->workbook([], null, 'dishes.xlsx', [['Brunch', null]]), ['mode' => 'replace']);
        $typesOnly->assertStatus(422);
        $this->assertStringContainsString('would be emptied', $typesOnly->json('errors.file.0'));

        $this->assertSame(1, DB::table('dishes')->whereNull('deleted_at')->count());
        $this->assertSame(1, DB::table('dish_types')->count());
    }

    public function test_a_replace_with_a_bad_row_removes_nothing(): void
    {
        $mains = $this->type('Mains');
        $this->dish('Adobo', [$mains]);
        $this->dish('Tea', [$mains]);

        $this->importAs($this->superAdmin(), $this->workbook([['Adobo', 'Nowhere', 100]]), ['mode' => 'replace'])->assertStatus(422);

        $this->assertSame(2, DB::table('dishes')->whereNull('deleted_at')->count());
    }

    public function test_a_dish_that_is_removed_frees_its_name(): void
    {
        $mains = $this->type('Mains');
        $keep = $this->dish('Keep', [$mains]);
        $this->dish('Taken Name', [$mains]);   // not in the file: it goes, so its name is free

        $this->importAs($this->superAdmin(), $this->workbook([
            [$keep->dish_id, 'Taken Name', 'Mains', 10],
        ], self::HEADINGS_ID), ['mode' => 'replace'])->assertOk();

        $this->assertSame(['Taken Name'], DB::table('dishes')->whereNull('deleted_at')->pluck('dish_name')->all());

        // In update mode the same file is refused: that dish stays and the name would exist twice.
        $other = $this->dish('Other', [$mains]);
        $this->dish('Stays', [$mains]);

        $this->importAs($this->superAdmin(), $this->workbook([
            [$other->dish_id, 'Stays', 'Mains', 10],
        ], self::HEADINGS_ID), ['mode' => 'update'])->assertStatus(422);
    }

    public function test_replacing_the_menu_needs_edit_and_delete_permissions(): void
    {
        $mains = $this->type('Mains');
        $this->dish('Adobo', [$mains]);
        $this->dish('Tea', [$mains]);
        $file = fn () => $this->workbook([['Adobo', 'Mains', 100]]);

        foreach ([['create dishes'], ['create dishes', 'edit dishes'], ['create dishes', 'delete dishes']] as $permissions) {
            $user = $this->userWith(...$permissions);

            $this->importAs($user, $file(), ['mode' => 'replace'])->assertForbidden();
            $this->importAs($user, $file(), ['mode' => 'replace', 'preview' => 1])->assertForbidden();
        }

        $this->assertSame(2, DB::table('dishes')->whereNull('deleted_at')->count());

        $this->importAs($this->userWith('create dishes', 'edit dishes', 'delete dishes'), $file(), ['mode' => 'replace'])->assertOk();
        $this->assertSame(1, DB::table('dishes')->whereNull('deleted_at')->count());
    }

    public function test_the_drawer_offers_replace_only_to_people_who_may_edit_and_delete(): void
    {
        $this->actingAs($this->superAdmin())->get(route('dishes.index'))
            ->assertOk()
            ->assertSee('value="replace"', false);

        $this->actingAs($this->userWith('view dishes', 'create dishes', 'edit dishes'))->get(route('dishes.index'))
            ->assertOk()
            ->assertSee('value="update"', false)
            ->assertDontSee('value="replace"', false);
    }

    public function test_a_description_or_ingredients_longer_than_the_column_is_a_row_error_not_a_database_error(): void
    {
        $this->type('Mains');

        // The columns are varchar(255) on MySQL: a longer text must be refused with its row, never reach the insert.
        $response = $this->importAs($this->superAdmin(), $this->workbook([
            ['Fits', 'Mains', 10, str_repeat('a', 255), null, str_repeat('b', 255)],
            ['Too long', 'Mains', 10, str_repeat('a', 256), null, str_repeat('b', 256)],
        ]));

        $response->assertStatus(422);
        $errors = $response->json('errors.file');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Row 3:', $errors[0]);
        $this->assertStringContainsString('Description', $errors[0]);
        $this->assertStringContainsString('Main Ingredients', $errors[0]);
        $this->assertSame(0, DB::table('dishes')->count());
    }

    public function test_the_import_is_a_four_step_modal_that_asks_what_to_do_before_the_template(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('dishes.index'))->assertOk()->getContent();

        // A modal (not a drawer), and the order of the steps: what to do, the template, the file, the review.
        $this->assertStringContainsString('import-dishes-modal', $html);
        $this->assertStringNotContainsString('import-dishes-drawer', $html);

        $positions = array_map(fn ($step) => strpos($html, 'data-import-step="' . $step . '"'), [1, 2, 3, 4]);
        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());

        // The template step has one help per mode, with the current menu offered for update and replace.
        foreach (['add', 'update', 'replace'] as $mode) {
            $this->assertStringContainsString('data-import-help="' . $mode . '"', $html);
        }
        $this->assertSame(2, substr_count($html, route('dishes.import.template', ['current' => 1])));

        // Someone who may only add sees one choice, the blank template, and no current menu.
        $only = $this->actingAs($this->userWith('view dishes', 'create dishes'))->get(route('dishes.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="update"', $only);
        $this->assertStringContainsString('import-dishes-modal', $only);
    }

    public function test_removing_many_dishes_is_one_statement_not_one_per_dish(): void
    {
        $mains = $this->type('Mains');
        for ($i = 1; $i <= 60; $i++) {
            $this->dish('Dish ' . $i, [$mains]);
        }
        $user = $this->superAdmin();
        $file = $this->workbook([['Dish 1', 'Mains', 100]]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->importAs($user, $file, ['mode' => 'replace'])->assertOk();
        $deletes = collect(DB::getQueryLog())->pluck('query')->map('strtolower')
            ->filter(fn ($sql) => str_contains($sql, '"deleted_at"') && str_starts_with($sql, 'update "dishes"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, DB::table('dishes')->whereNull('deleted_at')->count());
        $this->assertSame(1, $deletes); // ONE statement removes all 59 (Dish 1's own change is an upsert)
    }

    public function test_the_import_is_written_to_the_audit_trail_with_a_readable_name(): void
    {
        $this->assertSame('Imported dishes from Excel', config('audit.actions')['dishes.import']);
        $this->assertSame('Downloaded the dish import template', config('audit.actions')['dishes.import.template']);
    }
}
