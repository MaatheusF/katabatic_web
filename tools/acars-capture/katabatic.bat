@echo off
setlocal EnableExtensions EnableDelayedExpansion

REM ===============================================================
REM Katabatic - atalho de captura
REM
REM Uso:
REM   katabatic.bat setup
REM   katabatic.bat probe
REM   katabatic.bat KBT118
REM   katabatic.bat KBT118 PAFA PABT carga
REM   katabatic.bat KBT512 VEPU VNRB reposicionamento helicoptero
REM     (5o argumento opcional - categoria da aeronave: aviao ^(padrao,
REM     pode omitir^) ou helicoptero. So muda a deteccao de toque no
REM     solo/decolagem no script Python - ver --categoria em
REM     katabatic_capture.py. NAO existia neste atalho antes; ordem fixa
REM     e sempre CALLSIGN ORIGEM DESTINO TIPO [CATEGORIA], nunca
REM     CALLSIGN CATEGORIA ORIGEM DESTINO TIPO)
REM   katabatic.bat rebuild PASTA
REM     (gravacao que caiu sem Ctrl+C - queda de energia, crash - e por
REM     isso nunca gerou upload_payload.json; reconstroi a partir dos CSVs
REM     que ja estao na pasta, sem precisar do simulador aberto)
REM ===============================================================

REM ===============================================================
REM CONFIGURACAO ACARS
REM ===============================================================
set "KATABATIC_SERVER=http://localhost:8000"
set "KATABATIC_ACARS_TOKEN=1233523SDAS2312ASD"
set "KATABATIC_PILOT_CID=1234567"
REM Segundos entre POSTs de posicao pro Mapa ao vivo (ACARS fase 3) - 0
REM desliga so o heartbeat, sem desligar o inicio/fechamento do voo.
set "KATABATIC_ACARS_POS_INTERVAL=12"

REM ===============================================================
REM GUARDA OS ARGUMENTOS ANTES DE QUALQUER GOTO
REM ===============================================================
set "ARG1=%~1"
set "ARG2=%~2"
set "ARG3=%~3"
set "ARG4=%~4"
set "ARG5=%~5"

REM Tolera "--rebuild"/"--probe"/"--setup" (com traços) alem da forma
REM sem traços, ja que e um erro de digitacao facil de cometer.
if /i "%ARG1%"=="--rebuild" set "ARG1=rebuild"
if /i "%ARG1%"=="--probe" set "ARG1=probe"
if /i "%ARG1%"=="--setup" set "ARG1=setup"

REM ===============================================================
REM ENCONTRA PYTHON
REM ===============================================================

if defined PY goto :validate

for %%V in (313 312 311 310 39) do (
    if exist "%LOCALAPPDATA%\Programs\Python\Python%%V\python.exe" (
        set "PY=%LOCALAPPDATA%\Programs\Python\Python%%V\python.exe"
        goto :validate
    )

    if exist "C:\Program Files\Python%%V\python.exe" (
        set "PY=C:\Program Files\Python%%V\python.exe"
        goto :validate
    )

    if exist "C:\Python%%V\python.exe" (
        set "PY=C:\Python%%V\python.exe"
        goto :validate
    )
)

for /f "delims=" %%P in ('where python 2^>nul') do (
    echo %%P | find /i "WindowsApps" >nul
    if errorlevel 1 (
        set "PY=%%P"
        goto :validate
    )
)

where py >nul 2>&1
if not errorlevel 1 (
    set "PY=py"
    goto :validate
)

echo.
echo Nenhum Python utilizavel encontrado.
echo.
pause
exit /b 1


:validate

echo Python: %PY%

"%PY%" -c "import platform,sys; a=platform.architecture()[0]; print('Arquitetura:', a); sys.exit(0 if a=='64bit' else 2)" 2>nul

if errorlevel 2 (
    echo.
    echo ATENCAO: este Python e 32 bits.
    echo O SimConnect exige Python 64 bits.
    echo.
    pause
    exit /b 1
)

if errorlevel 1 (
    echo.
    echo O Python encontrado nao executou: %PY%
    echo.
    pause
    exit /b 1
)

REM ===============================================================
REM COMANDOS ESPECIAIS
REM ===============================================================

if /i "%ARG1%"=="setup" (
    "%PY%" -m pip install --upgrade pip
    "%PY%" -m pip install SimConnect
    echo.
    echo Pronto.
    echo.
    pause
    exit /b 0
)

if /i "%ARG1%"=="probe" (
    "%PY%" "%~dp0katabatic_capture.py" --probe
    pause
    exit /b 0
)

if /i "%ARG1%"=="rebuild" (
    if "%ARG2%"=="" (
        echo.
        echo Uso: katabatic.bat rebuild PASTA
        echo   PASTA e a pasta de uma gravacao existente que caiu sem
        echo   Ctrl+C ^(queda de energia, crash^) - precisa ter samples.csv
        echo   dentro. Exemplo:
        echo     katabatic.bat rebuild voos\20260823_141500_KBT118
        echo.
        pause
        exit /b 1
    )
    echo.
    echo Reconstruindo upload_payload.json a partir de: %ARG2%
    echo Pilot CID: %KATABATIC_PILOT_CID%
    echo.
    "%PY%" "%~dp0katabatic_capture.py" --rebuild "%ARG2%" --pilot-cid "%KATABATIC_PILOT_CID%"
    echo.
    echo Se deu certo, importe o upload_payload.json em /novo-voo ^(modo
    echo "Importar telemetria"^) pra publicar o voo.
    echo.
    pause
    exit /b 0
)

REM ===============================================================
REM SEM ARGUMENTOS
REM ===============================================================

if "%ARG1%"=="" (
    echo.
    echo Uso:
    echo   katabatic.bat setup
    echo   katabatic.bat probe
    echo   katabatic.bat KBT118
    echo   katabatic.bat KBT118 PAFA PABT carga
    echo   katabatic.bat KBT512 VEPU VNRB reposicionamento helicoptero
    echo     ^(5o argumento opcional - categoria: aviao/helicoptero^)
    echo   katabatic.bat rebuild PASTA
    echo.
    echo Envio ao servidor:
    echo   Server: %KATABATIC_SERVER%
    echo   Pilot CID: %KATABATIC_PILOT_CID%
    echo   Token: configurado
    echo.
    pause
    exit /b 0
)

REM ===============================================================
REM DEBUG
REM ===============================================================

REM Categoria (5o argumento) e opcional - vazio deixa o script Python cair
REM no padrao dele (aviao). So monta --categoria quando foi informado, pra
REM nao mandar "--categoria """ (vazio) e o argparse reclamar de escolha
REM invalida.
set "CATARG="
set "CATDISPLAY=aviao (padrao)"
if not "%ARG5%"=="" (
    set "CATARG=--categoria %ARG5%"
    set "CATDISPLAY=%ARG5%"
)

echo.
echo Callsign : %ARG1%
echo Origem   : %ARG2%
echo Destino  : %ARG3%
echo Tipo     : %ARG4%
echo Categoria: %CATDISPLAY%
echo Server   : %KATABATIC_SERVER%
echo Pilot CID: %KATABATIC_PILOT_CID%
echo.

REM ===============================================================
REM EXECUTA KATABATIC
REM ===============================================================

"%PY%" "%~dp0katabatic_capture.py" ^
    --record ^
    --callsign "%ARG1%" ^
    --origem "%ARG2%" ^
    --destino "%ARG3%" ^
    --tipo "%ARG4%" ^
    %CATARG% ^
    --server "%KATABATIC_SERVER%" ^
    --token "%KATABATIC_ACARS_TOKEN%" ^
    --pilot-cid "%KATABATIC_PILOT_CID%" ^
    --pos-interval "%KATABATIC_ACARS_POS_INTERVAL%"

echo.
pause