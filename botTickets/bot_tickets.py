# -*- coding: utf-8 -*-
"""Bot para crear 10 tickets en el sistema de soporte tecnico distrital."""
import csv
import json
import re
import sys
import time
from pathlib import Path

import requests
from bs4 import BeautifulSoup

if getattr(sys, "frozen", False):
    BASE_DIR = Path(sys.executable).parent
else:
    BASE_DIR = Path(__file__).parent
DATOS = json.loads((BASE_DIR / "datos.json").read_text(encoding="utf-8"))
BASE_URL = DATOS["base_url"].rstrip("/") + "/"
CUENTAS = DATOS.get("cuentas") or [DATOS["login"]]
TICKETS = DATOS["tickets"]

TIMEOUT = 20
DELAY = 1.5
HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
}


class SinFormulario(Exception):
    pass


class LimiteAlcanzado(SinFormulario):
    pass


def es_pagina_login(html: str) -> bool:
    return "login-card" in html or "Ingresar" in html


def ver_errores(html: str) -> str:
    """Busca mensajes de error/validacion en el texto visible del HTML."""
    soup = BeautifulSoup(html, "html.parser")
    for tag in soup(["script", "style", "noscript"]):
        tag.decompose()
    limpio = re.sub(r"\s+", " ", soup.get_text(" ", strip=True))
    marcas = [
        "limite", "límite", "máximo", "maximo", "bloqueado", "suspendido",
        "no se pudo", "no se puede", "seleccion", "selección",
        "inválido", "invalido", "incorrecto", "obligatorio", "requerido",
    ]
    for marca in marcas:
        m = re.search(r".{0,80}" + re.escape(marca) + r".{0,120}", limpio, re.I)
        if m:
            return m.group(0).strip()
    return ""


def conexion_ok() -> bool:
    """Verifica con un ping HTTP rapido que el servidor este alcanzable."""
    try:
        r = requests.get(BASE_URL, headers=HEADERS, timeout=5)
        return r.status_code < 500
    except requests.RequestException:
        return False


def login(session: requests.Session, cuenta: dict) -> bool:
    if not conexion_ok():
        print("[X] No se pudo conectar al servidor %s" % BASE_URL)
        print("    Posibles causas:")
        print("    - La PC no esta conectada a la red del distrito")
        print("    - El servidor esta apagado o bloqueado por firewall")
        print("    - Revisar 'base_url' en datos.json")
        return False
    url = BASE_URL + "login.php"
    r = session.get(url, headers=HEADERS, timeout=TIMEOUT)
    if not es_pagina_login(r.text):
        return True  # ya habia sesion

    soup = BeautifulSoup(r.text, "html.parser")
    form = soup.find("form")
    if form is None:
        print("[!] No se encontro el formulario de login")
        return False

    payload = {i.get("name"): i.get("value", "") for i in form.find_all("input")
               if i.get("name") and i.get("type", "text") != "submit"}
    payload["dni"] = cuenta["dni"]
    payload["password"] = cuenta["password"]
    accion = form.get("action") or "login.php"
    if accion.startswith("/"):
        accion = "http://10.0.20.181" + accion
    elif not accion.lower().startswith("http"):
        accion = BASE_URL + accion

    r2 = session.post(accion, data=payload, headers=HEADERS, timeout=TIMEOUT,
                      allow_redirects=True)
    if es_pagina_login(r2.text):
        print("[!] Login fallido: revisar DNI/contraseña en datos.json")
        print("    " + ver_errores(r2.text))
        return False
    print("[OK] Sesion iniciada como DNI %s" % cuenta["dni"])
    return True


def obtener_formulario(session: requests.Session):
    """Autenticado: parsea el formulario de ticket_nuevo.php y devuelve
    (datos_base, selects) donde datos_base tiene los hidden y valores por
    defecto, y selects mapea nombre -> {opciones, seleccionado}."""
    url = BASE_URL + "ticket_nuevo.php"
    r = session.get(url, headers=HEADERS, timeout=TIMEOUT)
    if es_pagina_login(r.text):
        raise SystemExit("[!] Sesion expirada durante el proceso")

    soup = BeautifulSoup(r.text, "html.parser")
    form = soup.find("form")
    if form is None:
        if "límite de tickets abiertos" in r.text.lower():
            raise LimiteAlcanzado(
                "limite del servidor: max. 5 tickets abiertos por escuela")
        raise SinFormulario("no se encontro el formulario de ticket")

    datos = {}
    selects = {}
    for inp in form.find_all("input"):
        nombre = inp.get("name")
        if not nombre:
            continue
        tipo = inp.get("type", "text")
        if tipo == "submit" or tipo == "button":
            continue
        datos[nombre] = inp.get("value", "")
    for ta in form.find_all("textarea"):
        nombre = ta.get("name")
        if nombre:
            datos[nombre] = ta.get_text("", strip=True)
    for sel in form.find_all("select"):
        nombre = sel.get("name")
        if not nombre:
            continue
        opciones = []
        for opt in sel.find_all("option"):
            valor = opt.get("value")
            if valor is None:
                valor = opt.get_text(strip=True)
            opciones.append((valor, opt.get_text(strip=True)))
        seleccionado = sel.get("value") or sel.find("option", selected=True)
        if seleccionado is not None and hasattr(seleccionado, "get"):
            seleccionado = seleccionado.get("value")
        elif seleccionado is not None and all(seleccionado != v for v, _ in opciones):
            seleccionado = None
        if seleccionado is None and opciones:
            seleccionado = opciones[0][0]
        selects[nombre] = {"opciones": opciones, "seleccionado": seleccionado}
        datos[nombre] = seleccionado
    return datos, selects, (form.get("action") or "ticket_nuevo.php")


def buscar_nombre(datos, selects, claves):
    for clave in claves:
        for nombre in list(datos) + list(selects):
            if clave in nombre.lower():
                return nombre
    return None


def elegir_valor(sel, deseado, campo):
    opciones = sel["opciones"]
    for valor, texto in opciones:
        if str(valor) == str(deseado) or texto.lower() == str(deseado).lower():
            return valor
    if opciones:
        print("[i] '%s' no esta en %s; usando '%s'"
              % (deseado, campo, opciones[0][0]))
        return opciones[0][0]
    return None


def crear_ticket(session, titulo, descripcion, categoria, prioridad, idx):
    try:
        datos, selects, accion = obtener_formulario(session)
    except LimiteAlcanzado as e:
        return "limite", str(e)
    except SinFormulario as e:
        return "fallo", str(e)
    if accion.startswith("/"):
        accion = "http://10.0.20.181" + accion
    elif not accion.lower().startswith("http"):
        accion = BASE_URL + accion

    print("[i] Campos del formulario: %s" % ", ".join(sorted(set(datos) | set(selects))))

    nombre_titulo = buscar_nombre(datos, selects, ["titulo", "título"])
    nombre_desc = buscar_nombre(datos, selects, ["descripcion"])
    nombre_cat = buscar_nombre(datos, selects, ["categoria"])
    nombre_prio = buscar_nombre(datos, selects, ["prioridad"])

    if nombre_titulo is None or nombre_desc is None:
        raise SystemExit("[!] No se identificaron los campos titulo/descripcion")

    payload = dict(datos)
    payload[nombre_titulo] = titulo
    payload[nombre_desc] = descripcion
    if nombre_cat:
        valor = elegir_valor(selects[nombre_cat], categoria, "categoria")
        if valor is not None:
            payload[nombre_cat] = valor
    if nombre_prio:
        valor = elegir_valor(selects[nombre_prio], prioridad, "prioridad")
        if valor is not None:
            payload[nombre_prio] = valor

    r = session.post(accion, data=payload, headers=HEADERS, timeout=TIMEOUT,
                     allow_redirects=True)
    if es_pagina_login(r.text):
        return "fallo", "sesion perdida"
    error = ver_errores(r.text)
    exito = re.search(r"ticket_lista|ticket_ver|ticket_detalle", r.url, re.I)
    exito2 = r.status_code in (302, 303) or "ticket_lista" in r.text
    if error and ("error" in error.lower() or "limite" in error.lower()
                  or "bloqueado" in error.lower() or "no se pudo" in error.lower()
                  or "inválido" in error.lower()):
        return "fallo", error
    if exito or exito2 or not error:
        return "creado", "ticket #%d enviado" % idx
    return "indeterminado", error or r.url


def main():
    if not CUENTAS:
        print("[X] No hay cuentas definidas en datos.json")
        sys.exit(1)

    idx_cuenta = 0
    session = requests.Session()
    if not login(session, CUENTAS[idx_cuenta]):
        sys.exit(1)

    resultados = []
    total = len(TICKETS)
    for i, tk in enumerate(TICKETS, 1):
        print("\n--- Ticket %d/%d: %s ---" % (i, total, tk["titulo"]))
        estado, detalle = None, ""
        reintentos = 0
        while True:
            estado, detalle = crear_ticket(
                session, tk["titulo"], tk["descripcion"],
                tk.get("categoria", ""), tk.get("prioridad", ""), i)
            if estado != "limite":
                break
            idx_cuenta += 1
            if idx_cuenta >= len(CUENTAS):
                print("[X] Todas las cuentas alcanzaron el limite de "
                      "tickets abiertos")
                estado, detalle = "fallo", "limite en todas las cuentas"
                break
            reintentos += 1
            print("[i] Limite alcanzado, cambiando a la cuenta %s (%d/%d)"
                  % (CUENTAS[idx_cuenta]["dni"], reintentos, len(CUENTAS)))
            session = requests.Session()
            if not login(session, CUENTAS[idx_cuenta]):
                estado, detalle = "fallo", "no se pudo iniciar sesion en la cuenta"
                break
            time.sleep(DELAY)
        print("[%s] %s -> %s" % ("OK" if estado == "creado" else "X",
                                 estado, detalle))
        resultados.append([i, estado, tk["titulo"], detalle])
        if i < total:
            time.sleep(DELAY)

    ruta_csv = BASE_DIR / "resultados.csv"
    with open(ruta_csv, "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["n", "estado", "titulo", "detalle"])
        w.writerows(resultados)
    creados = sum(1 for _, e, _, _ in resultados if e == "creado")
    print("\nResumen: %d/%d tickets creados -> %s"
          % (creados, total, ruta_csv))


if __name__ == "__main__":
    main()
