from pathlib import Path
import xlrd
p=Path(__file__).resolve().parents[1]/'.runtime/order-test.xls'
book=xlrd.open_workbook(p)
s=book.sheet_by_index(0)
values=[s.cell_value(r,c) for r in range(s.nrows) for c in range(s.ncols)]
assert s.name=='Заказ'
assert 'Артикул' in values and 'Наименование' in values
assert 'MJE13003BP' in values
assert 'ООО Тест' in values
assert any(isinstance(v,(float,int)) and abs(v-57.6)<.001 for v in values)
print('XLS VALIDATION PASSED: independently parsed BIFF8, Cyrillic, buyer and numeric totals.')
