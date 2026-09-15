// curl — HTTP-клиент с поддержкой заголовков, POST, verbose, сохранения в файл.
// Использование:
//   curl <url>                    — GET, вывод на stdout
//   curl -o <file> <url>          — GET, сохранить в файл
//   curl -O <url>                 — GET, сохранить с именем из URL
//   curl -X POST -d "data" <url>  — POST
//   curl -H "Key: Val" <url>      — добавить заголовок
//   curl -v <url>                 — verbose (заголовки ответа в stderr)
//   curl -L <url>                 — следовать редиректам (3xx)
//   curl -I <url>                 — только заголовки (HEAD)
#include "lib/libk.h"

#define CURL_MAX_HEADERS 16
#define CURL_MAX_REDIRECTS 5

static int parse_ip(const char* s, unsigned* a, unsigned* b, unsigned* c, unsigned* d) {
    *a = *b = *c = *d = 0;
    const char* p = s;
    for (int i = 0; i < 4; i++) {
        if (i > 0) { if (*p != '.') return 0; p++; }
        if (*p < '0' || *p > '9') return 0;
        unsigned v = 0; int digits = 0;
        while (*p >= '0' && *p <= '9' && digits < 3) { v = v*10 + (*p-'0'); p++; digits++; }
        if (digits == 0 || v > 255) return 0;
        if (i==0)*a=v; else if(i==1)*b=v; else if(i==2)*c=v; else *d=v;
    }
    return 1;
}

static int parse_url(const char* url, char* host, int* port, char* path) {
    const char* p = url;
    if (strncmp(p, "http://", 7) != 0) return -1;
    p += 7;
    const char* slash = strchr(p, '/');
    const char* colon = strchr(p, ':');
    if (colon && (!slash || colon < slash)) {
        int hlen = (int)(colon - p); memcpy(host, p, hlen); host[hlen] = 0;
        *port = atoi(colon + 1);
    } else {
        int hlen = slash ? (int)(slash - p) : (int)strlen(p);
        memcpy(host, p, hlen); host[hlen] = 0; *port = 80;
    }
    if (slash) strcpy(path, slash); else strcpy(path, "/");
    return 0;
}

static uint32_t resolve_host(const char* host) {
    unsigned a=0,b=0,c=0,d=0;
    if (parse_ip(host, &a,&b,&c,&d)) return (a<<24)|(b<<16)|(c<<8)|d;
    int fd = (int)sys_open("/etc/hosts", 0x0001, 0);
    if (fd < 0) return 0;
    char buf[4096]; long n = sys_read(fd, buf, sizeof(buf)-1); sys_close(fd);
    if (n <= 0) return 0; buf[n] = 0;
    char* line = buf;
    while (*line) {
        char* eol = strchr(line, '\n'); if (eol) *eol = 0;
        char* s = line; while (*s==' '||*s=='\t') s++;
        if (*s=='#'||*s==0) { line = eol?eol+1:line+strlen(line); continue; }
        char ipstr[64], h[256]; int ni=0,hi=0;
        while (*s && *s!=' ' && *s!='\t' && ni<63) ipstr[ni++]=*s++; ipstr[ni]=0;
        while (*s==' '||*s=='\t') s++;
        while (*s && *s!=' ' && *s!='\t' && hi<255) h[hi++]=*s++; h[hi]=0;
        if (strcmp(h, host)==0) {
            unsigned a2=0,b2=0,c2=0,d2=0;
            if (parse_ip(ipstr,&a2,&b2,&c2,&d2)) return (a2<<24)|(b2<<16)|(c2<<8)|d2;
        }
        line = eol?eol+1:line+strlen(line);
    }
    return 0;
}

/* ---- strcasestr (простая) ---- */
static char* my_strcasestr(const char* haystack, const char* needle) {
    if (!*needle) return (char*)haystack;
    for (; *haystack; haystack++) {
        const char* h = haystack;
        const char* n = needle;
        while (*h && *n && ((*h == *n) || (*h >= 'A' && *h <= 'Z' && *h + 32 == *n) ||
               (*n >= 'A' && *n <= 'Z' && *n + 32 == *h))) { h++; n++; }
        if (!*n) return (char*)haystack;
    }
    return 0;
}

/* ---- парсинг статус-строки "HTTP/1.0 200 OK" ---- */
static void parse_status(const char* headers, int* code, char* text, int cap) {
    *code = 0; text[0] = 0;
    const char* p = headers;
    /* пропускаем "HTTP/x.x " */
    while (*p && *p != ' ') p++;
    if (*p == ' ') p++;
    *code = atoi(p);
    while (*p && *p != ' ') p++;
    if (*p == ' ') p++;
    int i = 0;
    while (*p && *p != '\r' && *p != '\n' && i < cap-1) text[i++] = *p++;
    text[i] = 0;
}

#define strcasestr my_strcasestr
#define sscanf_resp parse_status
struct curl_opts {
    char method[8];      /* GET, POST, HEAD */
    char body[256];      /* тело POST */
    int body_len;
    char headers[CURL_MAX_HEADERS][128];
    int nheaders;
    char outfile[128];
    int verbose;
    int follow_redirects;
    int head_only;
};

static int do_request(const char* url, struct curl_opts* o, int redirect_count);

int main(int argc, char** argv) {
    if (argc < 2) {
        puts("usage: curl [options] <url>");
        puts("  -o <file>   save to file");
        puts("  -O          save with remote name");
        puts("  -X <meth>   request method (GET/POST/HEAD)");
        puts("  -d <data>   POST body");
        puts("  -H <hdr>    add header");
        puts("  -v          verbose");
        puts("  -L          follow redirects");
        puts("  -I          headers only (HEAD)");
        sys_exit(1);
    }

    struct curl_opts o;
    memset(&o, 0, sizeof(o));
    strcpy(o.method, "GET");

    int i = 1;
    while (i < argc - 1) {
        const char* a = argv[i];
        if (strcmp(a, "-o") == 0 && i+1 < argc-1) { strncpy(o.outfile, argv[i+1], 127); i+=2; }
        else if (strcmp(a, "-O") == 0) {
            /* имя файла из URL определим после парсинга */
            char path[256]; char host[128]; int port;
            if (parse_url(argv[argc-1], host, &port, path) == 0) {
                const char* fn = strrchr(path, '/');
                fn = fn ? fn+1 : "index.html";
                if (fn[0]) strncpy(o.outfile, fn, 127);
            }
            i++;
        }
        else if (strcmp(a, "-X") == 0 && i+1 < argc-1) { strncpy(o.method, argv[i+1], 7); i+=2; }
        else if (strcmp(a, "-d") == 0 && i+1 < argc-1) {
            strncpy(o.body, argv[i+1], 255); o.body_len = (int)strlen(o.body); i+=2;
        }
        else if (strcmp(a, "-H") == 0 && i+1 < argc-1) {
            if (o.nheaders < CURL_MAX_HEADERS) strncpy(o.headers[o.nheaders++], argv[i+1], 127);
            i+=2;
        }
        else if (strcmp(a, "-v") == 0) { o.verbose = 1; i++; }
        else if (strcmp(a, "-L") == 0) { o.follow_redirects = 1; i++; }
        else if (strcmp(a, "-I") == 0) { o.head_only = 1; strcpy(o.method, "HEAD"); i++; }
        else { printf("curl: unknown option '%s'\r\n", a); sys_exit(1); }
    }

    const char* url = argv[argc-1];
    return do_request(url, &o, 0);
}

static int do_request(const char* url, struct curl_opts* o, int redirect_count) {
    if (redirect_count > CURL_MAX_REDIRECTS) {
        puts("curl: too many redirects");
        sys_exit(1);
    }

    char host[128], path[256];
    int port = 80;
    if (parse_url(url, host, &port, path) != 0) {
        printf("curl: bad url '%s'\r\n", url);
        sys_exit(1);
    }

    uint32_t ip = resolve_host(host);
    if (ip == 0) { printf("curl: cannot resolve %s\r\n", host); sys_exit(1); }

    int fd = (int)sys_socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) { puts("curl: socket failed"); sys_exit(1); }

    struct sockaddr_in addr;
    addr.sin_family = AF_INET;
    addr.sin_port = (unsigned short)port; /* ядро само делает htons */
    addr.sin_addr = ip;

    if (o->verbose) printf("* Connecting to %s:%d (%x)\r\n", host, port, ip);
    if (sys_connect(fd, &addr, 10000) < 0) {
        puts("curl: connect failed"); sys_sock_close(fd); sys_exit(1);
    }

    /* Формируем запрос */
    char req[1024];
    int rlen = snprintf(req, sizeof(req),
        "%s %s HTTP/1.0\r\n"
        "Host: %s\r\n"
        "User-Agent: KnitOS-curl/0.1\r\n"
        "Connection: close\r\n",
        o->method, path, host);

    if (o->body_len > 0)
        rlen += snprintf(req+rlen, sizeof(req)-rlen, "Content-Length: %d\r\n", o->body_len);

    for (int i = 0; i < o->nheaders; i++)
        rlen += snprintf(req+rlen, sizeof(req)-rlen, "%s\r\n", o->headers[i]);

    rlen += snprintf(req+rlen, sizeof(req)-rlen, "\r\n");

    if (o->body_len > 0)
        rlen += snprintf(req+rlen, sizeof(req)-rlen, "%s", o->body);

    if (sys_send(fd, req, (unsigned long)rlen) < 0) {
        puts("curl: send failed"); sys_sock_close(fd); sys_exit(1);
    }

    /* Читаем ответ */
    char buf[4096];
    int in_body = 0;
    int status_code = 0;
    char status_text[128];
    int outfd = -1;
    long total = 0;
    char location[256];
    location[0] = 0;

    /* Открываем файл если нужно */
    if (o->outfile[0]) {
        outfd = (int)sys_open(o->outfile, 0x0102, 0644); /* O_WRONLY|O_CREAT|O_TRUNC */
        if (outfd < 0) { printf("curl: cannot open '%s'\r\n", o->outfile); sys_sock_close(fd); sys_exit(1); }
    }

    for (;;) {
        int n = (int)sys_recv(fd, buf, sizeof(buf), 3000);
        if (n <= 0) break;

        if (!in_body) {
            char* body = strstr(buf, "\r\n\r\n");
            if (body) {
                in_body = 1;
                int hdr_end = (int)(body - buf) + 4;
                /* печатаем заголовки если verbose */
                if (o->verbose) sys_write(2, buf, hdr_end);
                /* парсим статус */
                parse_status(buf, &status_code, status_text, sizeof(status_text));
                /* ищем Location */
                if (o->follow_redirects && status_code >= 300 && status_code < 400) {
                    char* loc = my_strcasestr(buf, "Location:");
                    if (loc) {
                        loc += 9; while (*loc == ' ') loc++;
                        int li = 0;
                        while (*loc && *loc != '\r' && *loc != '\n' && li < 255)
                            location[li++] = *loc++;
                        location[li] = 0;
                    }
                }
                n -= hdr_end;
                memmove(buf, body + 4, n);
            } else continue;
        }

        total += n;
        if (outfd >= 0) {
            if (sys_write(outfd, buf, (unsigned long)n) < 0) { puts("curl: write error"); break; }
        } else if (!o->verbose) {
            sys_write(1, buf, (unsigned long)n);
        }
    }

    sys_sock_close(fd);
    if (outfd >= 0) sys_close(outfd);

    if (o->verbose) {
        printf("* Status: %d %s\r\n", status_code, status_text);
        printf("* Received %ld bytes\r\n", total);
        if (location[0]) printf("* Redirect: %s\r\n", location);
    }

    /* Редирект */
    if (location[0] && o->follow_redirects) {
        if (o->verbose) printf("* Following redirect to %s\r\n", location);
        return do_request(location, o, redirect_count + 1);
    }

    sys_exit(status_code >= 400 ? 1 : 0);
}
