@rem Gradle Wrapper Script (Windows)
@if "%DEBUG%"=="" @echo off
setlocal
set DIRNAME=%~dp0
set APP_BASE_NAME=%~n0
set APP_HOME=%DIRNAME%

set CLASSPATH=%APP_HOME%\gradle\wrapper\gradle-wrapper.jar

for /f "tokens=*" %%i in ('where java 2^>nul') do set JAVA_EXE=%%i
if not defined JAVA_EXE set JAVA_EXE=java.exe

"%JAVA_EXE%" -classpath "%CLASSPATH%" org.gradle.wrapper.GradleWrapperMain %*
