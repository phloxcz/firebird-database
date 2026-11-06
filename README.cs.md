# phloxcz/firebird-database

[English](README.md) | **Česky**

**Firebird driver pro [Nette Database](https://github.com/nette/database), postavený na PDO_Firebird.**

Otestováno na Firebirdu 3, 4 a 5 s PHP 8.5. Funguje s Explorerem i s [`phloxcz/entity-database`](https://github.com/phloxcz/entity-database/blob/master/README.cs.md).

> ⚠️ Balíček se aktivně vyvíjí. API zatím není stabilní.

## Instalace

```bash
composer require phloxcz/firebird-database
```

```neon
database:
    default:
        dsn: 'firebird:dbname=localhost/3050:/data/app.fdb;charset=UTF8'
        user: SYSDBA
        password: masterkey
        options:
            driverClass: Phlox\FirebirdDatabase\FirebirdDriver
```

Používejte `charset=UTF8` i u databáze v jiné znakové sadě. Firebird text převede a API stejně nemůže jako JSON poslat nic jiného než UTF-8.

## Co PDO_Firebird dělá špatně a jak se to řeší

**Celé číslo zapsané do sloupce s desetinnými místy.** PDO uloží `6` do `NUMERIC(9,2)` tiše jako `0.06` a `price < 6` porovnává s `0.06`. Driver váže celá čísla jako text, který PDO převede správně. Platí to i pro kód používající `$connection->getPdo()->prepare()`.

**Typy sloupců ve výsledku.** PDO zná jen délku v bajtech a přesnost.

| Sloupec | Hodnota |
|---|---|
| `SMALLINT`, `INTEGER`, `BIGINT`, `NUMERIC(x,0)` | `int` |
| `NUMERIC`, `DECIMAL` s desetinnými místy | `float` |
| `FLOAT`, `DOUBLE PRECISION` | `float` |
| `BOOLEAN` | `bool` |
| `DATE`, `TIMESTAMP` | `Nette\Utils\DateTime` |
| `TIME` | `string`, nemá datum |
| `CHAR`, `VARCHAR`, `BLOB` | `string` |

`FLOAT` a `DOUBLE PRECISION` nejde podle délky odlišit od `CHAR(4)`, `CHAR(8)` nebo `BLOB` v jednobajtové znakové sadě. Když taková hodnota vypadá jako číslo, sloupec se dohledá v systémových tabulkách podle tabulek uvedených v dotazu a výsledek se pro připojení zapamatuje. Sloupec, který je v kterékoli z těch tabulek textový, zůstane textem, takže `'0123'` si nulu ponechá.

Výraz nebo sloupec pod jiným názvem dohledat nejde a posuzuje se podle tvaru: `SUM(PRICE)` s hodnotou `7.5` je `float` a `'0123'` zůstane textem, ale stejně tak i celé číslo jako `'5'` z `DOUBLE PRECISION`. Pokud na tom záleží, přetypujte ho v dotazu na `NUMERIC`, nebo ho čtěte pod jeho vlastním názvem.

## Omezení Firebirdu a PDO

- **ID nového řádku.** PDO_Firebird nemá `lastInsertId()`. Insert se zadaným klíčem vrátí řádek jako obvykle. Bez něj se [`phloxcz/entity-database`](https://github.com/phloxcz/entity-database/blob/master/README.cs.md) sám zeptá přes `RETURNING`, takže jeho `insert()` vrátí entitu, ať klíč pochází z identity sloupce, nebo z triggeru. Čisté Nette Database vrací počet řádků, tam klíč získáte přes `RETURNING`:

  ```php
  $id = $connection->fetchField('INSERT INTO BOOKS (TITLE) VALUES (?) RETURNING ID', $title);
  ```

  Ze stejného důvodu se identity sloupec nehlásí jako `autoincrement`, protože Nette by se po každém insertu ptalo PDO na poslední ID. Poznáte ho podle `$column['vendor']['IDENTITY_TYPE']`.

- **Více řádků v jednom insertu.** Firebird nemá vícořádkové `VALUES`, takže `insert([[...], [...]])` selže. Vkládejte po jednom řádku.

- **Datum s časem do sloupce `DATE`.** Firebird ho odmítne, i když jiné databáze čas oříznou. Půlnoc se zapisuje bez času, takže obyčejné datum funguje pro `DATE` i `TIMESTAMP`.

- **Sloupec `TIME`** se čte jako text a intervaly nejsou podporované jako hodnoty (`\DateInterval`).

- **Limit a offset** používají `FIRST` a `SKIP`, takže dotaz musí začínat `SELECT`, `UPDATE` nebo `DELETE`, ne `WITH`.

- **Parametr je dlouhý jako jeho sloupec.** Porovnání `VARCHAR(10)` s delším textem selže chybou „string right truncation". Pokud může být hodnota delší, přetypujte ji: `NAME = CAST(? AS VARCHAR(100))`. [`phloxcz/api-query`](https://github.com/phloxcz/api-query/blob/master/README.cs.md) to dělá sám.

## Chyby

`UniqueConstraintViolationException`, `ForeignKeyConstraintViolationException`, `NotNullConstraintViolationException` a `ConnectionException` přicházejí stejně jako na jiných databázích, cokoli dalšího jako `DriverException`.

## Názvy

Firebird převádí názvy bez uvozovek na velká písmena a Nette píše názvy v uvozovkách. `$explorer->table('USERS')` a `$row->EMAIL` jsou proto velkými písmeny, pokud tabulka nevznikla s názvem malými písmeny v uvozovkách. Entity generované přes [`phloxcz/entity-database`](https://github.com/phloxcz/entity-database/blob/master/README.cs.md) mohou mít hezčí názvy díky `rename:` v `entities.neon`.

Nativní typy se hlásí tak, jak byly deklarované, například `VARCHAR`, `NUMERIC`, `DOUBLE PRECISION` a `TIMESTAMP`, takže entity dostanou správné typy PHP. Syrové hodnoty ze systémových tabulek jsou v `$column['vendor']`.

## Přechod z Abra\FirebirdDatabase

Nahraďte `Abra\FirebirdDatabase\FirebirdDriver` za `Phlox\FirebirdDatabase\FirebirdDriver`.

Změny, které je potřeba v existujícím projektu zkontrolovat:

- **Celá čísla ve sloupcích `NUMERIC` jsou teď správně.** Řádky dřív zapsané přes PDO celým číslem mohou obsahovat setinu hodnoty. Zkontrolujte sloupce s desetinnými místy, do kterých šla celá čísla, třeba `UPDATE ... SET PRICE = ?` s `int`. Filtry, které je porovnávají s celým číslem, začnou nacházet to, co mají.
- **Text, který vypadá jako číslo, zůstává textem.** V jednobajtové znakové sadě se `CHAR(4)`, `CHAR(8)` a `BLOB` dřív měnily na `float` a kód jako `'0123'` na `123.0`.
- **Chyby unikátnosti, cizího klíče a not-null mají vlastní výjimky.** Dřív přicházely jako obyčejná `DriverException`, protože driver čekal jiné kódy, než PDO hlásí.
- **`nativetype`** je to, co bylo deklarované, třeba `VARCHAR`, ne to, jak tomu říkají systémové tabulky, třeba `VARYING`. Přegenerujte entity.
- **`formatLike()`** teď správně escapuje pomocí `ESCAPE`.

## Testy

Testy, které potřebují server, si ho berou z `TEST_DSN`:

```bash
docker run -d -p 3055:3050 -e FIREBIRD_ROOT_PASSWORD=masterkey -e FIREBIRD_DATABASE=test.fdb -e FIREBIRD_DATABASE_DEFAULT_CHARSET=UTF8 firebirdsql/firebird:5
TEST_DSN='firebird:dbname=127.0.0.1/3055:/var/lib/firebird/data/test.fdb;charset=UTF8' composer test
```

## Licence

[MIT](LICENSE)
