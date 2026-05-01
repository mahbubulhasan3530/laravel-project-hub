## Architechture

```bash
laravel-chart/
├── Chart.yaml
├── values.yaml
└── templates/
    ├── configmap.yaml
    ├── secret.yaml
    ├── deployment.yaml
    ├── service.yaml
    └── ingress.yaml
       _helpers.tpl

```
#### Step-1: Create values file
```bash
replicaCount: 2

envName: dev

image:
  laravel:
    repository: satabun3530/laravel
    tag: v1
  nginx:
    repository: nginx
    tag: alpine
  pullPolicy: Always

ingress:
  enabled: true
  className: nginx
  host: laravel.dev
  path: /
  pathType: Prefix

resources:
  requests:
    cpu: 256m
    memory: 256Mi
  limits:
    cpu: 512m
    memory: 512Mi

config:
  appName: "Laravel"
  appEnv: "local"
  appDebug: "true"
  appUrl: "http://localhost"
  logLevel: "debug"

secrets:
  appKey: "base64:JpBAWf7dNZr0mTRp9RBRGYaqxzhfhCR67E6FlNIgwxU="
  dbConnection: "null"
  sessionDriver: "file"

storage:
  enabled: true
  existingClaim: laravel-storage-pvc
  mountPath: /var/www/html/storage

service:
  type: NodePort
  port: 80
  nodePort: 31111
```

#### Step-2: Create a templates directory and place all the template files inside it.

##### Step 2.1: create deployment.yaml
```bash
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ include "laravel.fullname" . }}
  labels:
    app: {{ include "laravel.name" . }}
  annotations:
    secret.reloader.stakater.com/reload: {{ include "laravel.fullname" . }}-secret

spec:
  replicas: {{ .Values.replicaCount }}
  selector:
    matchLabels:
      app: {{ include "laravel.name" . }}
  template:
    metadata:
      labels:
        app: {{ include "laravel.name" . }}
    spec:
      securityContext:
        fsGroup: 33

      affinity:
        podAntiAffinity:
          preferredDuringSchedulingIgnoredDuringExecution:
            - weight: 100
              podAffinityTerm:
                labelSelector:
                  matchExpressions:
                    - key: app
                      operator: In
                      values:
                        - {{ include "laravel.name" . }}
                topologyKey: kubernetes.io/hostname

      volumes:
        - name: laravel-storage
          persistentVolumeClaim:
            claimName: {{ .Values.storage.existingClaim }}   
        - name: shared-files
          emptyDir: {}
        - name: nginx-config
          configMap:
            name: {{ include "laravel.fullname" . }}-nginx-config

      initContainers:
        - name: init-laravel
          image: "{{ .Values.image.laravel.repository }}:{{ .Values.image.laravel.tag }}"   
          imagePullPolicy: {{ .Values.image.pullPolicy }}
          command: ["/bin/sh", "-c"]
          args:
            - |
              cp -rp /var/www/html/. /shared/
              mkdir -p /storage/framework/sessions /storage/framework/views /storage/framework/cache /storage/logs
              chown -R 33:33 /storage
              chmod -R 775 /storage
          volumeMounts:
            - name: shared-files
              mountPath: /shared
            - name: laravel-storage
              mountPath: /storage

      containers:
        - name: laravel-app
          image: "{{ .Values.image.laravel.repository }}:{{ .Values.image.laravel.tag }}"   
          securityContext:
            runAsUser: 33
            runAsGroup: 33
          ports:
            - containerPort: 9000
              name: php-fpm
          volumeMounts:
            - name: shared-files
              mountPath: /var/www/html
            - name: laravel-storage
              mountPath: {{ .Values.storage.mountPath }}  
          envFrom:
            - configMapRef:
                name: {{ include "laravel.fullname" . }}-config
            - secretRef:
                name: {{ include "laravel.fullname" . }}-secret
          resources:
            {{- toYaml .Values.resources | nindent 12 }}
          readinessProbe: 
            tcpSocket:
              port: 9000
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:  
            tcpSocket:
              port: 9000
            initialDelaySeconds: 15
            periodSeconds: 20

        - name: nginx-sidecar
          image: {{ .Values.image.nginx.repository }}:{{ .Values.image.nginx.tag }}   
          ports:
            - containerPort: 80
              name: http
          volumeMounts:
            - name: nginx-config
              mountPath: /etc/nginx/conf.d/default.conf
              subPath: default.conf
            - name: shared-files
              mountPath: /var/www/html
              readOnly: true
          readinessProbe:
            httpGet:
              path: /
              port: 80
            initialDelaySeconds: 5
            periodSeconds: 10
```
##### Step 2.2: create configmap.yaml
```bash
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ include "laravel.fullname" . }}-config
data:
  APP_NAME: {{ .Values.config.appName | quote }}
  APP_ENV: {{ .Values.config.appEnv | quote }}
  APP_DEBUG: {{ .Values.config.appDebug | quote }}
  APP_URL: {{ .Values.config.appUrl | quote }}
  LOG_LEVEL: {{ .Values.config.logLevel | quote }}
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ include "laravel.fullname" . }}-nginx-config
data:
  default.conf: |
    server {
        listen 80;
        server_name _;
        root /var/www/html/public;
        index index.php;
        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }
        location ~ \.php$ {
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_index index.php;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        }
    }
```
##### step 2.3: Create a secret.yaml 
```bash
apiVersion: v1
kind: Secret
metadata:
  name: {{ include "laravel.fullname" . }}-secret
type: Opaque
stringData:
  APP_KEY: {{ .Values.secrets.appKey | quote }}
  DB_CONNECTION: {{ .Values.secrets.dbConnection | quote }}
  SESSION_DRIVER: {{ .Values.secrets.sessionDriver | quote }}
```

##### Step 2.4: Create a service.yaml 

```bash
apiVersion: v1
kind: Service
metadata:
  name: {{ include "laravel.fullname" . }}
spec:
  type: {{ .Values.service.type }}
  selector:
    app: {{ include "laravel.name" . }}
  ports:
    - protocol: TCP
      port: {{ .Values.service.port }}
      targetPort: 80
      {{- if eq .Values.service.type "NodePort" }}
      nodePort: {{ .Values.service.nodePort }}
      {{- end }}
```
##### Step 2.5 : Create a helpers.tpl
```bash
{{/*
Expand the name of the chart.
*/}}
{{- define "laravel.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a default fully qualified app name.
*/}}
{{- define "laravel.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}
```

## Step 3: Now Create PVC with Storageclasee

#### Step 3.1 : So we need install local path provision
```bash
kubectl apply -f https://raw.githubusercontent.com/rancher/local-path-provisioner/master/deploy/local-path-storage.yaml
```
#### Step 3.2 Now create storageclass.yaml file
```bash

# storageclass.yaml
apiVersion: storage.k8s.io/v1
kind: StorageClass
metadata:
  name: laravel-local-storage
provisioner: rancher.io/local-path
reclaimPolicy: Retain     
volumeBindingMode: WaitForFirstConsumer
```
##### Step 3.3 : Create pvc.yaml
```bash
# pvc.yaml
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: laravel-storage-pvc
  namespace: dev
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: laravel-local-storage # here this is storage-class name
  resources:
    requests:
      storage: 1Gi
```
**before apply we need to create namespace**
```bash
kubectl create namespace dev
```
**kubectl apply -f .**

#### Before applying, we need to check the dry run 
```bash
helm install laravel-app . -n dev --dry-run --debug
```

#### Then we apply 
```bash
helm install laravel-app . -n dev
```
#### Update any files then we have to need that command
```bash
helm upgrade laravel-app . -n dev
```
#### Delete the file then
```bash
helm uninstall laravel-app -n dev
```